<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pairs_seen')->default(0);
            $table->unsignedInteger('pairs_passed')->default(0);
            $table->string('ai_model')->nullable();
            $table->decimal('ai_cost', 10, 6)->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_scan_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 30);
            $table->string('base', 20);
            $table->unsignedSmallInteger('rank');
            $table->decimal('score', 6, 2);
            $table->decimal('price', 24, 10);
            $table->decimal('change_24h_pct', 10, 4);
            $table->decimal('quote_volume', 24, 2);
            $table->decimal('spread_pct', 10, 4);
            $table->decimal('volume_accel', 10, 4)->nullable();
            $table->boolean('breakout')->default(false);
            $table->decimal('extension_atr', 10, 4)->nullable();
            $table->json('reasons');
            $table->text('ai_note')->nullable();
            $table->timestamp('swarm_sent_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'swarm_sent_at']);
        });

        Schema::table('assets', function (Blueprint $table) {
            // Cadastrado só pelo explorador: não entra no "acompanha todos" das carteiras.
            $table->boolean('discovered')->default(false)->after('is_benchmark');
        });

        Schema::table('portfolios', function (Blueprint $table) {
            // Manda as melhores oportunidades do explorador aos agentes sozinho.
            $table->boolean('explorer_auto_swarm')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', fn (Blueprint $table) => $table->dropColumn('explorer_auto_swarm'));
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('discovered'));
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('opportunity_scans');
    }
};
