<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_votes', function (Blueprint $table) {
            $table->boolean('abstained')->default(false)->after('veto');
        });

        Schema::table('signals', function (Blueprint $table) {
            $table->json('calculation')->nullable()->after('scenario');
        });
    }

    public function down(): void
    {
        Schema::table('agent_votes', function (Blueprint $table) {
            $table->dropColumn('abstained');
        });

        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn('calculation');
        });
    }
};
