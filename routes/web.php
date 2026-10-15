<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\SignalDecisionController;
use App\Http\Controllers\TradingHaltController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('portfolios', PortfolioController::class)->only(['index', 'store', 'show']);
    Route::post('signals/{signal}/confirm', [SignalDecisionController::class, 'confirm'])->name('signals.confirm');
    Route::post('signals/{signal}/reject', [SignalDecisionController::class, 'reject'])->name('signals.reject');
    Route::post('trading/halt', [TradingHaltController::class, 'store'])->name('trading.halt');
    Route::delete('trading/halt', [TradingHaltController::class, 'destroy'])->name('trading.resume');
});

require __DIR__.'/settings.php';
