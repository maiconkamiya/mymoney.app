<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // Entrada das micro operações: compra limitada no preço sugerido (padrão) ou a mercado.
            $table->string('micro_entry_mode', 10)->default('limit');
            $table->unsignedSmallInteger('micro_limit_seconds')->default(60);
        });

        Schema::table('micro_cycles', function (Blueprint $table) {
            // Ciclo "pending": compra limitada esperando execução; some se vencer sem executar.
            $table->decimal('suggested_price', 20, 8)->nullable();
            $table->foreignId('buy_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('micro_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('buy_order_id');
            $table->dropColumn(['suggested_price', 'expires_at']);
        });

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['micro_entry_mode', 'micro_limit_seconds']);
        });
    }
};
