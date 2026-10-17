<?php

namespace App\Http\Requests;

use App\Models\Portfolio;
use App\Trading\Live\LiveLimits;
use App\Trading\TradingHalt;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePortfolioRequest extends FormRequest
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
        return [
            'auto_execute' => ['required', 'boolean'],
            'confirmacao' => ['nullable', 'string', 'max:20'],
            'notify_live_email' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Turning automatic execution on for a real portfolio sends real orders
     * with no one approving them, so it needs the live switch, a clean
     * credential, no general halt and the owner typing CONFIRMO.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Portfolio $portfolio */
                $portfolio = $this->route('portfolio');

                $turningOn = $this->boolean('auto_execute') && ! $portfolio->autoExecutesLive();

                if (! $portfolio->isLive() || ! $turningOn) {
                    return;
                }

                if (! app(LiveLimits::class)->enabled()) {
                    $validator->errors()->add('auto_execute', 'A execução real está desligada no servidor (TRADING_LIVE_ENABLED=false). Ligue no .env e reinicie os serviços antes.');

                    return;
                }

                if (app(TradingHalt::class)->isHalted()) {
                    $validator->errors()->add('auto_execute', 'Há uma parada geral acionada: retome a negociação antes de ligar a execução automática.');

                    return;
                }

                $credential = $portfolio->brokerCredential;

                if ($credential === null || $credential->can_withdraw || $credential->verified_at === null || $credential->user_id !== $portfolio->user_id) {
                    $validator->errors()->add('auto_execute', 'A carteira precisa de uma chave da Binance verificada e sem permissão de saque.');

                    return;
                }

                if ($this->input('confirmacao') !== 'CONFIRMO') {
                    $validator->errors()->add('confirmacao', 'Digite CONFIRMO, em maiúsculas, para liberar o envio de ordens reais sem aprovação.');
                }
            },
        ];
    }
}
