<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aportes da carteira simulada: entram no caixa numa data, sem reescrever o histórico.
        Schema::create('portfolio_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 20, 8);
            $table->timestamp('deposited_at');

            $table->index(['portfolio_id', 'deposited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_deposits');
    }
};
