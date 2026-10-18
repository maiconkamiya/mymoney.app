<?php

namespace App\Providers;

use Anthropic\Client;
use App\Agents\Llm\AnthropicLlmClient;
use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\PriceMoveTrigger;
use App\MarketData\BinanceTradeStream;
use App\MarketData\TradeStream;
use App\Models\Candle;
use App\News\Embeddings\Embedder;
use App\News\Embeddings\FakeEmbedder;
use App\News\Embeddings\HttpEmbedder;
use App\Settings\SystemSettings;
use App\Support\VersaoDoSistema;
use App\Trading\CandlePriceFeed;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Gateways\OrderGateway;
use App\Trading\PortfolioChangeBroadcaster;
use App\Trading\TradingHalt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $this->app->bind(TradeStream::class, BinanceTradeStream::class);
        // Uma leitura de arquivo por request/job; um deploy novo é visto sem reiniciar workers.
        $this->app->scoped(VersaoDoSistema::class);
        $this->app->bind(OrderGateway::class, fn () => app(config('trading.b3_gateway')));

        $this->app->singleton(LlmClient::class, fn (): LlmClient => match (config('agents.driver')) {
            'anthropic' => new AnthropicLlmClient(new Client(
                apiKey: config('services.anthropic.key'),
                requestOptions: ['timeout' => (float) config('agents.timeout_seconds', 120)],
            )),
            default => new FakeLlmClient,
        });

        $this->app->singleton(Embedder::class, fn (): Embedder => match (config('news.embeddings.driver')) {
            'http' => new HttpEmbedder(
                url: (string) config('news.embeddings.url'),
                key: (string) config('news.embeddings.key'),
                model: (string) config('news.embeddings.model'),
                dimensions: (int) config('news.embeddings.dimensions'),
                timeoutSeconds: (int) config('news.embeddings.timeout_seconds'),
            ),
            default => new FakeEmbedder((int) config('news.embeddings.dimensions')),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Switches from Configurações > Custos e serviços: on every boot and again before each job,
        // so long-lived workers follow a change without a restart.
        app(SystemSettings::class)->apply();
        Queue::before(fn () => app(SystemSettings::class)->apply());

        PortfolioChangeBroadcaster::register();

        Candle::saved(fn (Candle $candle) => app(PriceMoveTrigger::class)->handle($candle));
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
