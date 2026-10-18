<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->string('micro_rule', 30)->default('scalp');
        });

        Schema::table('micro_cycles', function (Blueprint $table) {
            $table->string('rule', 30)->default('scalp');
            $table->unsignedSmallInteger('max_hold_minutes')->nullable();
        });

        Schema::create('micro_backtests', function (Blueprint $table) {
            $table->id();
            $table->string('rule', 30);
            $table->json('params');
            $table->json('summary');
            $table->boolean('passed')->default(false);
            $table->timestamps();
            $table->index(['rule', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('micro_backtests');

        Schema::table('micro_cycles', function (Blueprint $table) {
            $table->dropColumn(['rule', 'max_hold_minutes']);
        });

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn('micro_rule');
        });
    }
};
