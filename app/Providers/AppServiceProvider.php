<?php

namespace App\Providers;

use Anthropic\Client;
use App\Agents\Llm\AnthropicLlmClient;
use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Trading\CandlePriceFeed;
use App\Trading\Contracts\PriceFeed;
use App\Trading\TradingHalt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PriceFeed::class, CandlePriceFeed::class);
        $this->app->singleton(TradingHalt::class);

        $this->app->singleton(LlmClient::class, fn (): LlmClient => match (config('agents.driver')) {
            'anthropic' => new AnthropicLlmClient(new Client(
                apiKey: config('services.anthropic.key'),
                requestOptions: ['timeout' => (float) config('agents.timeout_seconds', 120)],
            )),
            default => new FakeLlmClient,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
