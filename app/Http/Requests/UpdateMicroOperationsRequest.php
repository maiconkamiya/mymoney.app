<?php

namespace App\Http\Requests;

use App\Enums\Market;
use App\Enums\MicroRule;
use App\Models\MicroBacktest;
use App\Models\MicroCycle;
use App\Models\Portfolio;
use App\Trading\Live\LiveLimits;
use App\Trading\Micro\MicroTrader;
use App\Trading\Risk\RiskGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMicroOperationsRequest extends FormRequest
{
    /**
     * Only the owner edits a portfolio.
     */
    public function authorize(): bool
    {
        /** @var Portfolio $portfolio */
        $portfolio = $this->route('portfolio');

        return $portfolio->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $portfolio = $this->route('portfolio');
        $min = $portfolio instanceof Portfolio ? MicroTrader::minOrderValue($portfolio) : (float) config('trading.micro.min_order_value');

        return [
            'micro_enabled' => ['required', 'boolean'],
            'micro_rule' => ['sometimes', Rule::enum(MicroRule::class)],
            'micro_order_value' => ['required', 'numeric', 'min:'.$min, 'max:1000000'],
            'micro_daily_operations' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'micro_wallet_pct' => ['nullable', 'integer', 'min:1', 'max:100'],
            'micro_entry_mode' => ['sometimes', Rule::in(['limit', 'market'])],
            'micro_limit_seconds' => ['sometimes', 'integer', 'min:'.(int) config('trading.micro.limit.min_seconds'), 'max:'.(int) config('trading.micro.limit.max_seconds')],
            // "Stop na corretora": alvo e stop numa OCO na Binance.
            'micro_exchange_stop' => ['sometimes', 'boolean'],
            // Desligada, o ciclo não vende ao fim do tempo limite: só alvo ou stop.
            'micro_time_exit' => ['sometimes', 'boolean'],
            // Tempo máximo do ciclo; vazio segue o da regra.
            'micro_max_hold_minutes' => ['sometimes', 'nullable', 'integer', 'min:'.MicroCycle::MIN_HOLD_MINUTES, 'max:'.MicroCycle::MAX_HOLD_MINUTES],
            'micro_net_margin_pct' => ['required', 'numeric', 'min:0.05', 'max:5'],
            'micro_daily_target_pct' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            // Limite da guarda de risco nesta carteira; vazio segue o perfil de risco.
            'daily_loss_pct' => ['nullable', 'numeric', 'min:0.1', 'max:'.RiskGuard::MAX_DAILY_LOSS_PCT],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Portfolio $portfolio */
                $portfolio = $this->route('portfolio');

                if ($portfolio->market !== Market::Crypto) {
                    $validator->errors()->add('micro_enabled', 'Micro operações só existem em carteiras de cripto.');

                    return;
                }

                $rule = MicroRule::tryFrom((string) $this->input('micro_rule', $portfolio->micro_rule->value));

                // A new rule reaches real money only after its latest backtest passed on the validation period.
                if ($portfolio->isLive() && $rule !== null && $rule !== MicroRule::Scalp && $rule !== $portfolio->micro_rule) {
                    $backtest = MicroBacktest::latestFor($rule);

                    if ($backtest === null || ! $backtest->passed) {
                        $validator->errors()->add('micro_rule', $backtest === null
                            ? 'Esta regra ainda não tem backtest. Na carteira real ela só pode ser escolhida depois de passar no micro:backtest.'
                            : 'Esta regra não passou no último backtest ('.$backtest->created_at->format('d/m H:i').'). Na carteira real ela só pode ser escolhida depois de passar.');
                    }
                }

                if (! $portfolio->isLive() || ! $this->boolean('micro_enabled')) {
                    return;
                }

                if (! $portfolio->autoExecutesLive()) {
                    $validator->errors()->add('micro_enabled', 'Na carteira real, ligue antes a execução automática (CONFIRMO): as micro operações enviam ordens sem a sua aprovação.');

                    return;
                }

                $max = app(LiveLimits::class)->maxOrderValue();

                if ((float) $this->input('micro_order_value') > $max) {
                    $validator->errors()->add('micro_order_value', "O aporte por operação passa do máximo por ordem real ({$max}).");
                }
            },
        ];
    }
}
