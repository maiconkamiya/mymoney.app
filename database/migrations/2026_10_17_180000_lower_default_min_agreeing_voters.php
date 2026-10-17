<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With three useful agents (technical, risk, skeptic) a minimum of 4 agreeing
 * voters made every signal need unanimity. The default drops to 2; profiles
 * still on the old default of 4 follow it (there is no screen to edit it yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_agreeing_voters')->default(2)->change();
        });

        DB::table('risk_profiles')->where('min_agreeing_voters', 4)->update(['min_agreeing_voters' => 2]);
    }

    public function down(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_agreeing_voters')->default(4)->change();
        });

        DB::table('risk_profiles')->where('min_agreeing_voters', 2)->update(['min_agreeing_voters' => 4]);
    }
};
