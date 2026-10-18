<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Dia (UTC) em que a corretora deixa de negociar o par: dali em diante o app não compra e liquida o que houver.
            $table->date('trading_ends_on')->nullable()->after('discovered');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('trading_ends_on');
        });
    }
};
