<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->unique()->constrained('leads')->nullOnDelete();
            $table->foreignId('vendedor_id')->nullable()->constrained('vendedores')->nullOnDelete();
            $table->foreignId('visita_id')->nullable()->constrained('visitas')->nullOnDelete();
            $table->date('data');
            $table->time('horario');
            $table->string('responsavel');
            $table->string('telefone', 40)->nullable();
            $table->string('aluno')->nullable();
            $table->text('observacoes')->nullable();
            $table->string('status')->default('Agendado');

            $table->index(['unidade_id', 'data']);
            $table->index(['vendedor_id', 'data']);
            $table->index(['unidade_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->dropIndex(['unidade_id', 'data']);
            $table->dropIndex(['vendedor_id', 'data']);
            $table->dropIndex(['unidade_id', 'status']);
            $table->dropUnique(['lead_id']);
            $table->dropConstrainedForeignId('unidade_id');
            $table->dropConstrainedForeignId('lead_id');
            $table->dropConstrainedForeignId('vendedor_id');
            $table->dropConstrainedForeignId('visita_id');
            $table->dropColumn(['data', 'horario', 'responsavel', 'telefone', 'aluno', 'observacoes', 'status']);
        });
    }
};
