<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // Ativos marcados para "liquidar com lucro": o motor vende com lucro e não compra mais.
            $table->json('micro_liquidate_asset_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn('micro_liquidate_asset_ids');
        });
    }
};
