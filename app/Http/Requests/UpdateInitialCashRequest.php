<?php

namespace App\Http\Requests;

use App\Models\Portfolio;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInitialCashRequest extends FormRequest
{
    /**
     * Only the owner changes the initial balance.
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
            // A carteira real começa com o saldo que a conta tiver, por menor que seja.
            'initial_cash' => ['required', 'numeric', $this->portfolio()->isLive() ? 'min:0.01' : 'min:100', 'max:100000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'initial_cash.required' => 'Informe o novo saldo inicial.',
            'initial_cash.numeric' => 'O saldo inicial precisa ser um número.',
            'initial_cash.min' => $this->portfolio()->isLive() ? 'O valor investido precisa ser maior que zero.' : 'O saldo inicial mínimo é 100.',
            'initial_cash.max' => 'O saldo inicial passa do limite permitido.',
        ];
    }

    /**
     * A reduction cannot push the cash below zero. Real portfolios only change the base of the
     * result: their cash comes from the broker.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $portfolio = $this->portfolio();

                if ($portfolio->isLive()) {
                    return;
                }

                if (! $validator->errors()->has('initial_cash') && $portfolio->cash + $this->delta() < 0) {
                    $validator->errors()->add('initial_cash', 'Esse valor deixaria o caixa negativo. O caixa atual é '.number_format($portfolio->cash, 2, ',', '.').'.');
                }
            },
        ];
    }

    public function delta(): float
    {
        return round((float) $this->input('initial_cash') - $this->portfolio()->initial_cash, 8);
    }

    private function portfolio(): Portfolio
    {
        /** @var Portfolio $portfolio */
        $portfolio = $this->route('portfolio');

        return $portfolio;
    }
}
