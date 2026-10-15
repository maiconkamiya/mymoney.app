<?php

namespace App\Http\Requests;

use App\Enums\Market;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortfolioRequest extends FormRequest
{
    /**
     * Only paper portfolios can be created from the UI until live brokers exist.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'market' => ['required', Rule::enum(Market::class)],
            'initial_cash' => ['required', 'numeric', 'min:100', 'max:100000000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'auto_execute' => ['boolean'],
        ];
    }
}
