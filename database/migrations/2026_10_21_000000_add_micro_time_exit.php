<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // "Vender ao fim do tempo limite": desligada, o ciclo micro só sai pelo alvo ou pelo stop.
            $table->boolean('micro_time_exit')->default(true);
            // Tempo máximo do ciclo em minutos escolhido no card; vazio segue o da regra.
            $table->unsignedSmallInteger('micro_max_hold_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['micro_time_exit', 'micro_max_hold_minutes']);
        });
    }
};
