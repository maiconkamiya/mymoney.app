<?php

use App\Http\Controllers\BrokerCredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NovidadesController;
use App\Http\Controllers\PortfolioAgentActivityController;
use App\Http\Controllers\PortfolioChartsController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PortfolioReportController;
use App\Http\Controllers\SignalDecisionController;
use App\Http\Controllers\TradingHaltController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('novidades', [NovidadesController::class, 'show'])->name('novidades');
    Route::post('novidades/vista', [NovidadesController::class, 'marcarComoVista'])->name('novidades.vista');

    Route::resource('portfolios', PortfolioController::class)->only(['index', 'store', 'show']);
    Route::get('portfolios/{portfolio}/agentes', PortfolioAgentActivityController::class)->name('portfolios.agents');
    Route::get('portfolios/{portfolio}/graficos', PortfolioChartsController::class)->name('portfolios.charts');
    Route::get('portfolios/{portfolio}/reports/tax.csv', [PortfolioReportController::class, 'tax'])->name('portfolios.reports.tax');
    Route::post('signals/{signal}/confirm', [SignalDecisionController::class, 'confirm'])->name('signals.confirm');
    Route::post('signals/{signal}/reject', [SignalDecisionController::class, 'reject'])->name('signals.reject');
    Route::post('broker-credentials', [BrokerCredentialController::class, 'store'])->name('broker-credentials.store');
    Route::post('trading/halt', [TradingHaltController::class, 'store'])->name('trading.halt');
    Route::delete('trading/halt', [TradingHaltController::class, 'destroy'])->name('trading.resume');
});

require __DIR__.'/settings.php';
