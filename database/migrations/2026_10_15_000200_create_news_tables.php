<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('url', 2048);
            $table->char('url_hash', 64)->unique();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('published_at')->nullable();
            // The moment the item became available to us; simulations must only see news fetched before "now".
            $table->timestamp('fetched_at')->index();
            // Stored as JSON here so SQLite works in tests; switch to a pgvector column in production.
            $table->json('embedding')->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('news_asset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->decimal('sentiment', 4, 3);
            $table->decimal('relevance', 4, 3);
            $table->boolean('is_new_fact')->default(true);
            $table->json('analysis')->nullable();
            $table->timestamps();

            $table->unique(['news_item_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_asset');
        Schema::dropIfExists('news_items');
    }
};
