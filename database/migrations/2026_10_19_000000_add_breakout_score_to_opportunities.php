<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            // Escore de rompimento de alta (0 a 100) e o valor de cada sinal que o compôs.
            $table->decimal('breakout_score', 6, 2)->nullable()->after('score');
            $table->json('breakout_components')->nullable()->after('breakout_score');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['breakout_score', 'breakout_components']);
        });
    }
};
