<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetInsightController;
use App\Http\Controllers\BrokerCredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MicroOperationsController;
use App\Http\Controllers\NovidadesController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\PortfolioAgentActivityController;
use App\Http\Controllers\PortfolioAssetsController;
use App\Http\Controllers\PortfolioChartsController;
use App\Http\Controllers\PortfolioCloneController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PortfolioDepositController;
use App\Http\Controllers\PortfolioExplorerFollowController;
use App\Http\Controllers\PortfolioInitialCashController;
use App\Http\Controllers\PortfolioPanicController;
use App\Http\Controllers\PortfolioReportController;
use App\Http\Controllers\PortfolioSignalsController;
use App\Http\Controllers\SignalDecisionController;
use App\Http\Controllers\TradingHaltController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('novidades', [NovidadesController::class, 'show'])->name('novidades');
    Route::post('novidades/vista', [NovidadesController::class, 'marcarComoVista'])->name('novidades.vista');

    Route::resource('portfolios', PortfolioController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('portfolios/{portfolio}/clonar', PortfolioCloneController::class)->name('portfolios.clone');
    Route::patch('portfolios/{portfolio}/saldo-inicial', PortfolioInitialCashController::class)->name('portfolios.initial-cash');
    Route::post('portfolios/{portfolio}/aportes', [PortfolioDepositController::class, 'store'])->name('portfolios.deposits.store');
    Route::put('portfolios/{portfolio}/micro-operacoes', [MicroOperationsController::class, 'update'])->name('portfolios.micro');
    Route::post('portfolios/{portfolio}/micro-operacoes/zerar-maior-saldo', [MicroOperationsController::class, 'resetPeak'])->name('portfolios.reset-peak');
    Route::put('portfolios/{portfolio}/micro-operacoes/liquidar', [MicroOperationsController::class, 'liquidate'])->name('portfolios.liquidate');
    Route::post('portfolios/{portfolio}/micro-operacoes/liquidar-agora', [MicroOperationsController::class, 'liquidateNow'])->name('portfolios.liquidate-now');
    Route::put('portfolios/{portfolio}/ativos', [PortfolioAssetsController::class, 'update'])->name('portfolios.assets.update');
    Route::get('ativos', [AssetController::class, 'index'])->name('assets.index');
    Route::get('ativos/busca', [AssetController::class, 'search'])->middleware('throttle:30,1')->name('assets.search');
    Route::post('ativos', [AssetController::class, 'store'])->middleware('throttle:20,1')->name('assets.store');
    Route::get('ativos/{asset}', [AssetInsightController::class, 'show'])->whereNumber('asset')->name('assets.show');
    Route::post('ativos/{asset}/analise', [AssetInsightController::class, 'store'])->whereNumber('asset')->middleware('throttle:6,1')->name('assets.insight');
    Route::put('ativos/{asset}/fim-negociacao', [AssetController::class, 'updateTradingEnd'])->name('assets.trading-end');
    Route::delete('ativos/{asset}', [AssetController::class, 'destroy'])->name('assets.destroy');
    Route::post('ativos/oportunidades/{opportunity}/acompanhar', [OpportunityController::class, 'track'])->middleware('throttle:20,1')->name('opportunities.track');
    Route::post('ativos/oportunidades/{opportunity}/agentes', [OpportunityController::class, 'swarm'])->middleware('throttle:10,1')->name('opportunities.swarm');
    Route::put('portfolios/{portfolio}/explorador', [OpportunityController::class, 'autoSwarm'])->name('portfolios.explorer');
    Route::put('portfolios/{portfolio}/seguir-explorador', [PortfolioExplorerFollowController::class, 'update'])->name('portfolios.explorer-follow');
    Route::post('portfolios/{portfolio}/parar-tudo', [PortfolioPanicController::class, 'store'])->name('portfolios.panic');
    Route::delete('portfolios/{portfolio}/parar-tudo', [PortfolioPanicController::class, 'destroy'])->name('portfolios.release');
    Route::get('portfolios/{portfolio}/agentes', PortfolioAgentActivityController::class)->name('portfolios.agents');
    Route::get('portfolios/{portfolio}/sinais', PortfolioSignalsController::class)->name('portfolios.signals');
    Route::get('portfolios/{portfolio}/graficos', PortfolioChartsController::class)->name('portfolios.charts');
    Route::get('portfolios/{portfolio}/reports/tax.csv', [PortfolioReportController::class, 'tax'])->name('portfolios.reports.tax');
    Route::post('signals/{signal}/confirm', [SignalDecisionController::class, 'confirm'])->name('signals.confirm');
    Route::post('signals/{signal}/reject', [SignalDecisionController::class, 'reject'])->name('signals.reject');
    Route::post('broker-credentials', [BrokerCredentialController::class, 'store'])->name('broker-credentials.store');
    Route::post('trading/halt', [TradingHaltController::class, 'store'])->name('trading.halt');
    Route::delete('trading/halt', [TradingHaltController::class, 'destroy'])->name('trading.resume');
});

require __DIR__.'/settings.php';
