<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_credentials', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('can_withdraw');
        });
    }

    public function down(): void
    {
        Schema::table('broker_credentials', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};
