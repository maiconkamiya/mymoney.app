<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_votes', function (Blueprint $table) {
            // Chart reading of the Technical agent: expected scenario, supports, resistances and timeframes used.
            $table->json('analysis')->nullable()->after('target_price');
        });
    }

    public function down(): void
    {
        Schema::table('agent_votes', function (Blueprint $table) {
            $table->dropColumn('analysis');
        });
    }
};
