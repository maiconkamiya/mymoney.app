<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // Ativos acompanhados seguem o ranking do explorador a cada varredura.
            $table->boolean('explorer_follow')->default(false);
            $table->unsignedSmallInteger('explorer_follow_size')->default(20);
            $table->unsignedSmallInteger('explorer_follow_min_hours')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', fn (Blueprint $table) => $table->dropColumn(['explorer_follow', 'explorer_follow_size', 'explorer_follow_min_hours']));
    }
};
