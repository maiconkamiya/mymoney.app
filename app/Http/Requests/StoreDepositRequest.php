<?php

namespace App\Http\Requests;

use App\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepositRequest extends FormRequest
{
    /**
     * Only the owner adds money to a portfolio.
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
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Informe o valor do aporte.',
            'amount.numeric' => 'O valor do aporte precisa ser um número.',
            'amount.min' => 'O aporte precisa ser maior que zero.',
            'amount.max' => 'O aporte passa do limite permitido.',
        ];
    }

    private function portfolio(): Portfolio
    {
        /** @var Portfolio $portfolio */
        $portfolio = $this->route('portfolio');

        return $portfolio;
    }
}
