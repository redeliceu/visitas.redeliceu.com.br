<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campanhas', function (Blueprint $table) {
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $table->date('inicio')->nullable();
            $table->date('fim')->nullable();
            $table->unsignedInteger('meta')->nullable();
            $table->dateTime('atualizado_em')->nullable();
            $table->unique('unidade_id');
        });

        Schema::table('metas_vendedores', function (Blueprint $table) {
            $table->foreignId('campanha_id')->nullable()->constrained('campanhas')->restrictOnDelete();
            $table->foreignId('vendedor_id')->nullable()->constrained('vendedores')->restrictOnDelete();
            $table->unsignedInteger('meta')->default(0);
            $table->unique(['campanha_id', 'vendedor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('metas_vendedores', function (Blueprint $table) {
            $table->dropUnique(['campanha_id', 'vendedor_id']);
            $table->dropConstrainedForeignId('campanha_id');
            $table->dropConstrainedForeignId('vendedor_id');
            $table->dropColumn('meta');
        });

        Schema::table('campanhas', function (Blueprint $table) {
            $table->dropUnique(['unidade_id']);
            $table->dropConstrainedForeignId('unidade_id');
            $table->dropColumn(['inicio', 'fim', 'meta', 'atualizado_em']);
        });
    }
};
