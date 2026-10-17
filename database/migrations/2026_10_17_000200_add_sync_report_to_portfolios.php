<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // Última leitura da conta da corretora e o que ela encontrou, moeda por moeda.
            $table->timestamp('synced_at')->nullable();
            $table->json('sync_report')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['synced_at', 'sync_report']);
        });
    }
};
