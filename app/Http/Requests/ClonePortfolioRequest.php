<?php

namespace App\Http\Requests;

use App\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;

class ClonePortfolioRequest extends FormRequest
{
    /**
     * Only the owner clones a portfolio.
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
        return [];
    }
}
