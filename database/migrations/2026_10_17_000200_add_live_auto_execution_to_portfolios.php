<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // Quando o dono digitou CONFIRMO para a execução automática de dinheiro real.
            $table->timestamp('live_auto_confirmed_at')->nullable()->after('auto_execute');
            // "Parar tudo": enquanto preenchido, nenhuma ordem sai desta carteira.
            $table->timestamp('orders_blocked_at')->nullable()->after('live_auto_confirmed_at');
            $table->string('orders_blocked_reason')->nullable()->after('orders_blocked_at');
            $table->boolean('notify_live_email')->default(false)->after('orders_blocked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['live_auto_confirmed_at', 'orders_blocked_at', 'orders_blocked_reason', 'notify_live_email']);
        });
    }
};
