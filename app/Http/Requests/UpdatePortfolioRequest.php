<?php

namespace App\Http\Requests;

use App\Models\Portfolio;
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
        ];
    }

    /**
     * Real portfolios keep manual approval: turning automatic execution on is refused.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Portfolio $portfolio */
                $portfolio = $this->route('portfolio');

                if ($portfolio->isLive() && $this->boolean('auto_execute')) {
                    $validator->errors()->add('auto_execute', 'Carteira real exige aprovação manual de cada ordem: a execução automática não pode ser ligada.');
                }
            },
        ];
    }
}
