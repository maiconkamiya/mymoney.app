<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('market');
            $table->string('name')->nullable();
            $table->string('quote_currency', 10);
            $table->decimal('lot_size', 20, 8)->default(1);
            $table->decimal('tick_size', 20, 8)->default('0.01');
            $table->string('sector')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['market', 'symbol']);
        });

        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('timeframe', 4);
            $table->timestamp('open_at');
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->decimal('volume', 28, 8);

            $table->unique(['asset_id', 'timeframe', 'open_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candles');
        Schema::dropIfExists('assets');
    }
};
