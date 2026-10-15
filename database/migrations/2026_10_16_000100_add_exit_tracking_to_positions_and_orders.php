<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('signal_id')->nullable()->after('asset_id')->constrained()->nullOnDelete();
            $table->decimal('target_price', 20, 8)->nullable()->after('stop_price');
            $table->string('exit_reason')->nullable()->after('closed_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('reason');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signal_id');
            $table->dropColumn(['target_price', 'exit_reason']);
        });
    }
};
