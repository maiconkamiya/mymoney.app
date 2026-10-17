<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // Parte do saldo em USDT que as micro operações podem manter em ciclos abertos (1 a 100%).
            $table->unsignedTinyInteger('micro_wallet_pct')->default(100);
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn('micro_wallet_pct');
        });
    }
};
