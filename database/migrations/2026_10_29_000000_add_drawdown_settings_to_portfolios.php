<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #222: queda máxima desde o maior saldo por carteira (vazio segue o perfil de risco) e o
     * momento e o patrimônio de quando o dono zerou o maior saldo (a guarda conta o maior saldo dali em diante).
     */
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->decimal('max_drawdown_pct', 6, 4)->nullable();
            $table->timestamp('peak_reset_at')->nullable();
            $table->decimal('peak_reset_equity', 20, 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['max_drawdown_pct', 'peak_reset_at', 'peak_reset_equity']);
        });
    }
};
