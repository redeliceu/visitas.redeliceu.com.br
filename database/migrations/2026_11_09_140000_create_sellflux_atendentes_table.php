<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellflux_atendentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('sellflux_user_id');
            $table->string('nome');
            $table->foreignId('vendedor_id')->nullable()->constrained('vendedores')->nullOnDelete();
            $table->string('situacao')->default('pendente');
            $table->dateTime('visto_em');
            $table->foreignId('alterado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // SellFlux user ids belong to one project, and each unit has its own.
            $table->unique(['unidade_id', 'sellflux_user_id']);
            $table->index(['unidade_id', 'situacao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellflux_atendentes');
    }
};
