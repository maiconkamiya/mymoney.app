<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // "Stop na corretora": o ciclo micro sai por uma OCO na Binance (alvo + stop), não pelo stop do app.
            $table->boolean('micro_exchange_stop')->default(false);
        });

        Schema::table('orders', function (Blueprint $table) {
            // As duas pernas de uma OCO (alvo e stop) carregam o mesmo grupo: executada uma, a outra sai.
            $table->string('oco_group', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['oco_group']);
            $table->dropColumn('oco_group');
        });

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn('micro_exchange_stop');
        });
    }
};
