<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            // Set when an item is a semantic duplicate of an earlier one; such items skip triage.
            $table->unsignedBigInteger('duplicate_of_id')->nullable()->index();
        });

        if ($this->usesPgvector()) {
            $dimensions = (int) config('news.embeddings.dimensions');

            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement("ALTER TABLE news_items ALTER COLUMN embedding TYPE vector({$dimensions}) USING embedding::text::vector");
            DB::statement('CREATE INDEX news_items_embedding_hnsw ON news_items USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        if ($this->usesPgvector()) {
            DB::statement('DROP INDEX IF EXISTS news_items_embedding_hnsw');
            DB::statement('ALTER TABLE news_items ALTER COLUMN embedding TYPE json USING embedding::text::json');
        }

        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn('duplicate_of_id');
        });
    }

    private function usesPgvector(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql' && config('news.vector_store', 'auto') !== 'memory';
    }
};
