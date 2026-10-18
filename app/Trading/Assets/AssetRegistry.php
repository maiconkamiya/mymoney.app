<?php

namespace App\Trading\Assets;

use App\Enums\Market;
use App\Enums\OrderStatus;
use App\Enums\SignalStage;
use App\Jobs\BackfillAssetCandlesJob;
use App\MarketData\BinanceSymbols;
use App\MarketData\BrapiB3;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Position;
use App\Models\Signal;
use App\Trading\B3\B3Rules;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro global de ativos. O símbolo é um fato público, então o cadastro é de todos;
 * quem escolhe o que cada carteira acompanha é o dono da carteira. Todo símbolo é
 * confirmado na Binance (cripto) ou na brapi (B3) antes de ser salvo.
 */
class AssetRegistry
{
    public function __construct(
        private readonly BinanceSymbols $binance,
        private readonly BrapiB3 $brapi,
    ) {}

    /**
     * @throws ValidationException quando o símbolo é inválido, já cadastrado ou a fonte não o conhece
     */
    public function add(Market $market, string $symbol, string $actor, bool $discovered = false): Asset
    {
        $symbol = strtoupper(trim($symbol));

        $this->assertFormat($market, $symbol);

        $existing = Asset::query()->where('market', $market)->where('symbol', $symbol)->first();

        if ($existing?->active) {
            throw ValidationException::withMessages(['symbol' => "{$symbol} já está cadastrado."]);
        }

        $attributes = match ($market) {
            Market::Crypto => $this->cryptoAttributes($symbol),
            Market::B3 => $this->b3Attributes($symbol),
        };

        $asset = $existing ?? new Asset(['market' => $market, 'symbol' => $symbol]);
        $asset->fill($attributes + ['active' => true, 'is_benchmark' => false, 'discovered' => $discovered])->save();

        AuditLog::record($actor, 'asset.created', $asset, ['symbol' => $symbol, 'market' => $market->value, 'reactivated' => $existing !== null, 'discovered' => $discovered]);

        BackfillAssetCandlesJob::dispatch($asset->id);

        return $asset;
    }

    /**
     * O par cripto cadastrado e ativo; quando não está, cadastra como achado do
     * explorador, que não entra no "acompanha todos" das carteiras.
     *
     * @throws ValidationException quando a Binance não conhece o par
     */
    public function discover(string $symbol, string $actor): Asset
    {
        $existing = Asset::query()->where('market', Market::Crypto)->where('symbol', strtoupper($symbol))->where('active', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->add(Market::Crypto, $symbol, $actor, discovered: true);
    }

    /**
     * Motivos que impedem remover o ativo agora; vazio quando pode sair.
     *
     * @return list<string>
     */
    public function blockers(Asset $asset): array
    {
        $blockers = [];

        if ($asset->is_benchmark) {
            $blockers[] = 'É a referência do relatório de desempenho.';
        }

        if (Position::query()->where('asset_id', $asset->id)->whereNull('closed_at')->exists()) {
            $blockers[] = 'Há posição aberta neste ativo.';
        }

        if (Signal::query()->where('asset_id', $asset->id)->whereIn('stage', [SignalStage::Scanned, SignalStage::Analyzed, SignalStage::Approved, SignalStage::Confirmed])->exists()) {
            $blockers[] = 'Há sinal em andamento neste ativo.';
        }

        if (Order::query()->where('asset_id', $asset->id)->where('status', OrderStatus::Pending)->exists()) {
            $blockers[] = 'Há ordem pendente neste ativo.';
        }

        if ($asset->portfolios()->exists()) {
            $blockers[] = 'Há carteira acompanhando este ativo: tire-o da escolha da carteira antes.';
        }

        return $blockers;
    }

    /**
     * Remove o ativo. Com histórico (sinais, ordens, posições), só desativa para não perder o registro.
     *
     * @return 'removido'|'desativado'
     *
     * @throws ValidationException quando algo ainda usa o ativo
     */
    public function remove(Asset $asset, string $actor): string
    {
        $blockers = $this->blockers($asset);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['asset' => implode(' ', $blockers)]);
        }

        $hasHistory = Signal::query()->where('asset_id', $asset->id)->exists()
            || Order::query()->where('asset_id', $asset->id)->exists()
            || Position::query()->where('asset_id', $asset->id)->exists();

        AuditLog::record($actor, 'asset.removed', $asset, ['symbol' => $asset->symbol, 'market' => $asset->market->value, 'kept_history' => $hasHistory]);

        if ($hasHistory) {
            $asset->update(['active' => false]);

            return 'desativado';
        }

        $asset->delete();

        return 'removido';
    }

    private function assertFormat(Market $market, string $symbol): void
    {
        $valid = match ($market) {
            Market::Crypto => (bool) preg_match('/^[A-Z0-9]{1,20}USDT$/', $symbol),
            Market::B3 => (bool) preg_match('/^[A-Z]{4}\d{1,2}F?$/', $symbol),
        };

        if (! $valid) {
            throw ValidationException::withMessages(['symbol' => $market === Market::Crypto
                ? 'Informe um par em USDT, como SOLUSDT.'
                : 'Informe um ticker da B3, como PETR4.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cryptoAttributes(string $symbol): array
    {
        $pair = $this->lookup(fn () => $this->binance->find($symbol), 'a Binance');

        if ($pair === null) {
            throw ValidationException::withMessages(['symbol' => "{$symbol} não é um par spot em USDT negociável na Binance."]);
        }

        return [
            'name' => $pair['base'],
            'quote_currency' => 'USDT',
            'lot_size' => $pair['lot_size'],
            'tick_size' => $pair['tick_size'],
            'min_qty' => $pair['min_qty'],
            'min_notional' => $pair['min_notional'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function b3Attributes(string $symbol): array
    {
        $quote = $this->lookup(fn () => $this->brapi->find($symbol), 'a brapi');

        if ($quote === null) {
            throw ValidationException::withMessages(['symbol' => "{$symbol} não foi encontrado na B3 (brapi)."]);
        }

        return [
            'name' => $quote['name'],
            'quote_currency' => 'BRL',
            'lot_size' => B3Rules::lotSizeFor($symbol),
            'tick_size' => B3Rules::TICK,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function lookup(callable $call, string $source): mixed
    {
        try {
            return $call();
        } catch (ConnectionException|RequestException) {
            throw ValidationException::withMessages(['symbol' => "Não foi possível consultar {$source} agora. Tente de novo em instantes."]);
        }
    }
}
