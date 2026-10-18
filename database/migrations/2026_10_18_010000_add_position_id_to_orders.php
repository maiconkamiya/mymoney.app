<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Posição dona da venda: a execução de uma venda limitada só reduz a posição que a registrou.
            $table->foreignId('position_id')->nullable()->after('asset_id')->constrained()->nullOnDelete();
        });

        // Vendas limitadas do alvo já registradas: a posição é a do ciclo que as registrou.
        $placed = DB::table('audit_log')
            ->where('action', 'micro.target_order_placed')
            ->where('subject_type', 'App\Models\MicroCycle')
            ->get(['subject_id', 'payload']);

        foreach ($placed as $row) {
            $orderId = json_decode((string) $row->payload, true)['order_id'] ?? null;
            $positionId = DB::table('micro_cycles')->where('id', $row->subject_id)->value('position_id');

            if ($orderId !== null && $positionId !== null) {
                DB::table('orders')->where('id', $orderId)->whereNull('position_id')->update(['position_id' => $positionId]);
            }
        }

        // Vendas limitadas executadas sem execução registrada: a conciliação acusava divergência para sempre.
        $missing = DB::table('orders')
            ->where('side', 'sell')
            ->where('type', 'limit')
            ->where('status', 'filled')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('fills')->whereColumn('fills.order_id', 'orders.id'))
            ->get();

        foreach ($missing as $order) {
            DB::table('fills')->insert([
                'order_id' => $order->id,
                'qty' => $order->qty,
                'price' => $order->price,
                'fee' => $order->fees,
                'executed_at' => $order->filled_at ?? $order->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
