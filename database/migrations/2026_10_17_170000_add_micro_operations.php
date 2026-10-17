<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // Modo "Micro operações": desligado por padrão, valores definidos pelo dono.
            $table->boolean('micro_enabled')->default(false);
            $table->decimal('micro_order_value', 20, 8)->default('20');
            $table->decimal('micro_net_margin_pct', 6, 3)->default('0.200');
            $table->decimal('micro_daily_target', 20, 8)->nullable();
            $table->decimal('micro_daily_loss_limit', 20, 8)->nullable();
        });

        Schema::create('micro_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open');
            $table->decimal('qty', 20, 8);
            $table->decimal('entry_price', 20, 8);
            $table->decimal('target_price', 20, 8);
            $table->decimal('stop_price', 20, 8);
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('fees', 20, 8)->default(0);
            $table->decimal('net_pnl', 20, 8)->nullable();
            $table->decimal('gross_margin_pct', 6, 3);
            $table->string('exit_reason')->nullable();
            $table->json('analysis')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'status']);
            $table->index(['portfolio_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('micro_cycles');

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['micro_enabled', 'micro_order_value', 'micro_net_margin_pct', 'micro_daily_target', 'micro_daily_loss_limit']);
        });
    }
};
