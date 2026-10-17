<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Filtros da corretora guardados para montar ordens válidas (Binance: LOT_SIZE.minQty e NOTIONAL.minNotional).
            $table->decimal('min_qty', 28, 8)->nullable()->after('tick_size');
            $table->decimal('min_notional', 28, 8)->nullable()->after('min_qty');
        });

        // Ativos que a carteira acompanha. Sem linhas, a carteira acompanha todos os ativos ativos do mercado.
        Schema::create('portfolio_asset', function (Blueprint $table) {
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['portfolio_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_asset');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['min_qty', 'min_notional']);
        });
    }
};
