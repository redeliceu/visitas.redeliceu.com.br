<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('unidade_id')
                ->constrained('unidades')
                ->restrictOnDelete();

            $table->foreignId('vendedor_id')
                ->nullable()
                ->constrained('vendedores')
                ->nullOnDelete();

            $table->dateTime('data')->nullable();

            $table->string('responsavel')->nullable();
            $table->string('telefone')->nullable();
            $table->string('telefone_secundario')->nullable();
            $table->string('email')->nullable();
            $table->string('aluno')->nullable();

            $table->date('nascimento')->nullable();

            $table->foreignId('serie_id')
                ->nullable()
                ->constrained('series')
                ->restrictOnDelete();

            $table->foreignId('ciclo_id')
                ->nullable()
                ->constrained('ciclos')
                ->restrictOnDelete();

            $table->foreignId('turno_id')
                ->nullable()
                ->constrained('turnos')
                ->restrictOnDelete();

            $table->string('canal_contato')->nullable();

            $table->foreignId('status_id')
                ->nullable()
                ->constrained('lead_status')
                ->restrictOnDelete();

            $table->dateTime('retorno_data')->nullable();

            $table->text('observacoes')->nullable();

            $table->string('origem')->default('Manual');

            $table->dateTime('data_matricula')->nullable();

            // Valor monetário em %.
            $table->integer('desconto')->nullable();

            // Parâmetros de rastreamento de campanhas.
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();

            $table->timestamps();

            $table->index(['unidade_id', 'status_id']);
            $table->index('vendedor_id');
            $table->index('telefone');
            $table->index('email');
            $table->index('utm_campaign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
