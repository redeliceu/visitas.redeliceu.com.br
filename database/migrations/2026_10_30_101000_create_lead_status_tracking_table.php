<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_status_tracking', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->dateTime('started_at');
        });

        DB::table('lead_status_tracking')->insert([
            'id' => 1,
            'started_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_status_tracking');
    }
};
