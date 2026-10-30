<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('vendedor_id')->nullable()->constrained('vendedores')->nullOnDelete();
            $table->foreignId('agendamento_id')->nullable()->unique()->constrained('agendamentos')->nullOnDelete();
            $table->date('data');
            $table->time('horario')->nullable();
            $table->string('responsavel');
            $table->string('telefone', 40)->nullable();
            $table->string('telefone2', 40)->nullable();
            $table->string('aluno')->nullable();
            $table->unsignedSmallInteger('idade')->nullable();
            $table->string('serie')->nullable();
            $table->string('situacao')->default('Aguardando');
            $table->string('instituicao')->nullable();
            $table->string('fonte')->default('Manual');
            $table->string('status')->default('Aguardando');
            $table->text('observacoes')->nullable();
            $table->dateTime('retorno_data')->nullable();
            $table->string('motivo_perda')->nullable();

            $table->index(['unidade_id', 'data']);
            $table->index(['vendedor_id', 'data']);
            $table->index(['unidade_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            $table->dropIndex(['unidade_id', 'data']);
            $table->dropIndex(['vendedor_id', 'data']);
            $table->dropIndex(['unidade_id', 'status']);
            $table->dropUnique(['agendamento_id']);
            $table->dropConstrainedForeignId('unidade_id');
            $table->dropConstrainedForeignId('lead_id');
            $table->dropConstrainedForeignId('vendedor_id');
            $table->dropConstrainedForeignId('agendamento_id');
            $table->dropColumn([
                'data',
                'horario',
                'responsavel',
                'telefone',
                'telefone2',
                'aluno',
                'idade',
                'serie',
                'situacao',
                'instituicao',
                'fonte',
                'status',
                'observacoes',
                'retorno_data',
                'motivo_perda',
            ]);
        });
    }
};
