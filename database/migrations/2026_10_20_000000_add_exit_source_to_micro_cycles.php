<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('micro_cycles', function (Blueprint $table) {
            // De onde veio o preço de saída: "venda" do app, "binance" (venda feita fora do app) ou "cotacao" (estimado, #208).
            $table->string('exit_source', 10)->nullable();
        });

        DB::table('micro_cycles')->whereNotNull('exit_price')->update(['exit_source' => 'venda']);

        // Ciclos que saíram da conta sem venda no app ficaram sem preço e com resultado vazio (o QKC da carteira 2):
        // passam a ter o fechamento da vela de 1 min da hora em que fecharam, com a taxa de venda de sempre.
        // Os de 17/10 ficam como estão: ali a sincronização fechava por engano posições que continuavam no saldo.
        $feeRate = (float) config('trading.costs.crypto.fee_rate');

        DB::table('micro_cycles')->where('status', 'closed')->whereNull('exit_price')->where('closed_at', '>=', '2026-10-18 12:00:00')->orderBy('id')->get()
            ->each(function (object $cycle) use ($feeRate): void {
                $close = DB::table('candles')
                    ->where('asset_id', $cycle->asset_id)
                    ->where('timeframe', '1m')
                    ->where('open_at', '<=', $cycle->closed_at)
                    ->orderByDesc('open_at')
                    ->value('close');

                if ($close === null) {
                    return;
                }

                $price = (float) $close;
                $fees = (float) $cycle->fees + (float) $cycle->qty * $price * $feeRate;

                DB::table('micro_cycles')->where('id', $cycle->id)->update([
                    'exit_price' => $price,
                    'exit_source' => 'cotacao',
                    'fees' => $fees,
                    'net_pnl' => (float) $cycle->qty * ($price - (float) $cycle->entry_price) - $fees,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('micro_cycles', function (Blueprint $table) {
            $table->dropColumn('exit_source');
        });
    }
};
