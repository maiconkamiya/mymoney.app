<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta e perda do dia passam a ser % do saldo do início do dia (#191, #192). A perda vira o limite
     * da guarda de risco da carteira; vazio segue o perfil de risco (2%).
     */
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->decimal('micro_daily_target_pct', 8, 4)->nullable();
            $table->decimal('daily_loss_pct', 6, 4)->nullable();
        });

        // Converte pelo capital inicial. A perda só é aproveitada quando é mais rígida que a da guarda,
        // para nenhuma carteira ficar com a guarda mais frouxa sem o dono escolher.
        DB::table('portfolios')
            ->where('initial_cash', '>', 0)
            ->where(fn ($query) => $query->whereNotNull('micro_daily_target')->orWhereNotNull('micro_daily_loss_limit'))
            ->orderBy('id')
            ->each(function (object $portfolio): void {
                $guard = 100 * (float) (DB::table('risk_profiles')->where('id', $portfolio->risk_profile_id)->value('max_daily_loss_pct') ?? 0.02);
                $loss = $portfolio->micro_daily_loss_limit === null ? null : 100 * (float) $portfolio->micro_daily_loss_limit / (float) $portfolio->initial_cash;

                DB::table('portfolios')->where('id', $portfolio->id)->update([
                    'micro_daily_target_pct' => $portfolio->micro_daily_target === null ? null : min(100, round(100 * (float) $portfolio->micro_daily_target / (float) $portfolio->initial_cash, 4)),
                    'daily_loss_pct' => $loss !== null && $loss < $guard ? max(0.1, round($loss, 4)) : null,
                ]);
            });

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['micro_daily_target', 'micro_daily_loss_limit']);
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->decimal('micro_daily_target', 20, 8)->nullable();
            $table->decimal('micro_daily_loss_limit', 20, 8)->nullable();
        });

        DB::table('portfolios')->update([
            'micro_daily_target' => DB::raw('micro_daily_target_pct * initial_cash / 100.0'),
            'micro_daily_loss_limit' => DB::raw('daily_loss_pct * initial_cash / 100.0'),
        ]);

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['micro_daily_target_pct', 'daily_loss_pct']);
        });
    }
};
