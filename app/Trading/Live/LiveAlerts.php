<?php

namespace App\Trading\Live;

use App\Enums\OrderStatus;
use App\Events\LiveOrderUpdated;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Notifications\LiveOrderNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the owner, in real time (Reverb) and by e-mail when they asked for it,
 * what happened with real money. A failed notice never stops trading logic.
 */
class LiveAlerts
{
    public function order(Order $order): void
    {
        $portfolio = $order->portfolio;
        $order->loadMissing('asset');
        $failed = $order->status === OrderStatus::Rejected || $order->status === OrderStatus::Cancelled;
        $side = $order->side->value === 'buy' ? 'Compra' : 'Venda';
        $what = "{$side} de {$order->qty} {$order->asset->symbol}";

        $message = $failed
            ? "{$what} falhou: ".($order->reject_reason ?? 'recusada.')
            : "{$what} enviada à Binance (".($order->status === OrderStatus::Filled ? 'executada' : 'aguardando').').';

        $this->send($portfolio, $failed ? 'failed' : 'sent', $message, [
            'order_id' => $order->id,
            'symbol' => $order->asset->symbol,
            'side' => $order->side->value,
            'qty' => $order->qty,
            'price' => $order->price,
            'status' => $order->status->value,
        ]);
    }

    public function refused(Portfolio $portfolio, Signal $signal, string $reason): void
    {
        $this->send($portfolio, 'refused', "Sinal de {$signal->asset->symbol} não virou ordem real: {$reason}", [
            'signal_id' => $signal->id,
            'symbol' => $signal->asset->symbol,
        ]);
    }

    /**
     * @param  array{cancelled: list<string>, failed: list<string>}  $result
     */
    public function panic(Portfolio $portfolio, array $result): void
    {
        $message = '"Parar tudo" acionado: execução automática desligada e novas ordens bloqueadas.'
            .($portfolio->isLive() ? ' Ordens abertas canceladas: '.count($result['cancelled']).'.' : '')
            .($result['failed'] !== [] ? ' Atenção: '.count($result['failed']).' cancelamento(s) falharam, confira na Binance.' : '');

        $this->send($portfolio, 'panic', $message, ['cancelled' => count($result['cancelled']), 'failed' => count($result['failed'])]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(Portfolio $portfolio, string $kind, string $message, array $payload): void
    {
        try {
            LiveOrderUpdated::dispatch($portfolio->user_id, $portfolio->id, $kind, $message, $payload);
        } catch (Throwable $e) {
            Log::warning('live.alert_broadcast_failed', ['portfolio' => $portfolio->id, 'error' => $e->getMessage()]);
        }

        if (! $portfolio->notify_live_email) {
            return;
        }

        try {
            $portfolio->user->notify(new LiveOrderNotification($portfolio->name, 'MyMoney.App: '.match ($kind) {
                'sent' => 'ordem real enviada',
                'failed' => 'ordem real falhou',
                'refused' => 'sinal não virou ordem real',
                default => 'Parar tudo acionado',
            }, $message));
        } catch (Throwable $e) {
            Log::warning('live.alert_mail_failed', ['portfolio' => $portfolio->id, 'error' => $e->getMessage()]);
        }
    }
}
