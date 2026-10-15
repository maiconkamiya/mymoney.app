<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained();
            $table->string('stage')->default('scanned');
            $table->string('side')->nullable();
            $table->decimal('score', 5, 4)->nullable();
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('stop_price', 20, 8)->nullable();
            $table->decimal('target_price', 20, 8)->nullable();
            $table->string('decided_by')->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('scenario')->nullable();
            $table->json('stage_history')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'stage']);
        });

        Schema::create('agent_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->constrained()->cascadeOnDelete();
            $table->string('agent');
            $table->unsignedTinyInteger('round')->default(1);
            $table->string('action');
            $table->decimal('confidence', 4, 3);
            $table->boolean('veto')->default(false);
            $table->json('reasons')->nullable();
            $table->json('evidence_ids')->nullable();
            $table->decimal('stop_price', 20, 8)->nullable();
            $table->decimal('target_price', 20, 8)->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->constrained();
            $table->string('broker');
            $table->string('external_id')->nullable();
            $table->string('side');
            $table->string('type');
            $table->decimal('qty', 20, 8);
            $table->decimal('price', 20, 8)->nullable();
            $table->decimal('stop_price', 20, 8)->nullable();
            $table->string('status');
            $table->decimal('fees', 20, 8)->default(0);
            $table->text('reject_reason')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 20, 8);
            $table->decimal('price', 20, 8);
            $table->decimal('fee', 20, 8)->default(0);
            $table->timestamp('executed_at');
        });

        Schema::create('agent_scores', function (Blueprint $table) {
            $table->id();
            $table->string('agent');
            $table->string('market');
            $table->unsignedInteger('window')->default(100);
            $table->unsignedInteger('signals')->default(0);
            $table->unsignedInteger('hits')->default(0);
            $table->decimal('hit_rate', 5, 4)->default(0);
            $table->decimal('weight', 6, 4)->default(1);
            $table->timestamps();

            $table->unique(['agent', 'market']);
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('actor');
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('agent_scores');
        Schema::dropIfExists('fills');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('agent_votes');
        Schema::dropIfExists('signals');
    }
};
