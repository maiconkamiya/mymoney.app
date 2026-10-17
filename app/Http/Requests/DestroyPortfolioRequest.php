<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use App\Enums\SignalStage;
use App\Models\Portfolio;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DestroyPortfolioRequest extends FormRequest
{
    /** Etapas em que o sinal ainda pode virar ordem. */
    public const IN_PROGRESS = [SignalStage::Scanned, SignalStage::Analyzed, SignalStage::Approved, SignalStage::Confirmed];

    /**
     * Only the owner deletes a portfolio.
     */
    public function authorize(): bool
    {
        return $this->portfolio()->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:'.$this->portfolio()->name],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => 'Digite o nome da carteira para confirmar.',
            'confirmation.in' => 'O nome digitado não confere com o da carteira.',
        ];
    }

    /**
     * Only simulated portfolios go, and never while something is still in flight.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $portfolio = $this->portfolio();

                if ($portfolio->isLive()) {
                    $validator->errors()->add('portfolio', 'Carteira real não pode ser excluída por aqui. Só carteiras simuladas podem ser excluídas.');

                    return;
                }

                $signals = $portfolio->signals()->whereIn('stage', self::IN_PROGRESS)->count();
                $orders = $portfolio->orders()->where('status', OrderStatus::Pending)->count();

                if ($signals > 0 || $orders > 0) {
                    $validator->errors()->add('portfolio', 'A carteira tem '.collect([
                        $signals > 0 ? $signals.' '.($signals === 1 ? 'sinal em andamento' : 'sinais em andamento') : null,
                        $orders > 0 ? $orders.' '.($orders === 1 ? 'ordem pendente' : 'ordens pendentes') : null,
                    ])->filter()->implode(' e ').'. Confirme ou descarte os sinais e aguarde as ordens antes de excluir.');
                }
            },
        ];
    }

    private function portfolio(): Portfolio
    {
        /** @var Portfolio $portfolio */
        $portfolio = $this->route('portfolio');

        return $portfolio;
    }
}
