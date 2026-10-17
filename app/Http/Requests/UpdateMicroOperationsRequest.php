<?php

namespace App\Http\Requests;

use App\Enums\Market;
use App\Models\Portfolio;
use App\Trading\Live\LiveLimits;
use App\Trading\Micro\MicroTrader;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

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
            'micro_order_value' => ['required', 'numeric', 'min:'.$min, 'max:1000000'],
            'micro_daily_operations' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'micro_net_margin_pct' => ['required', 'numeric', 'min:0.05', 'max:5'],
            'micro_daily_target' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'micro_daily_loss_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
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
