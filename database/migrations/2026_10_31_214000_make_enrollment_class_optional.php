<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->foreignId('turma_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('matriculas')->whereNull('turma_id')->exists()) {
            throw new RuntimeException('Não é possível exigir turma enquanto houver matrículas sem turma.');
        }

        Schema::table('matriculas', function (Blueprint $table) {
            $table->foreignId('turma_id')->nullable(false)->change();
        });
    }
};
