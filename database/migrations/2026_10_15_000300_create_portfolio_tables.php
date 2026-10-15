<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('max_order_pct', 5, 4)->default('0.05');
            $table->decimal('max_position_pct', 5, 4)->default('0.15');
            $table->decimal('max_daily_loss_pct', 5, 4)->default('0.02');
            $table->decimal('max_drawdown_pct', 5, 4)->default('0.10');
            $table->unsignedSmallInteger('max_open_positions')->default(5);
            $table->decimal('consensus_threshold', 4, 3)->default('0.35');
            $table->unsignedTinyInteger('min_agreeing_voters')->default(4);
            $table->boolean('require_stop')->default(true);
            $table->timestamps();
        });

        Schema::create('broker_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('broker');
            $table->string('label');
            $table->text('api_key');
            $table->text('api_secret');
            $table->boolean('can_withdraw')->default(false);
            $table->timestamps();
        });

        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_profile_id')->constrained();
            $table->foreignId('broker_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('market');
            $table->string('mode')->default('paper');
            $table->string('broker')->default('paper');
            $table->string('status')->default('draft');
            $table->decimal('initial_cash', 20, 8);
            $table->decimal('cash', 20, 8);
            $table->string('benchmark')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('auto_execute')->default(false);
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained();
            $table->decimal('qty', 20, 8);
            $table->decimal('avg_price', 20, 8);
            $table->decimal('stop_price', 20, 8)->nullable();
            $table->decimal('realized_pnl', 20, 8)->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->timestamp('taken_at');
            $table->decimal('equity', 20, 8);
            $table->decimal('cash', 20, 8);
            $table->decimal('exposure', 20, 8);
            $table->decimal('drawdown', 6, 5)->default(0);

            $table->index(['portfolio_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_snapshots');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('portfolios');
        Schema::dropIfExists('broker_credentials');
        Schema::dropIfExists('risk_profiles');
    }
};
