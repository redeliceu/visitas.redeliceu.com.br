<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('visita_id')->unique()->constrained('visitas')->restrictOnDelete();
            $table->foreignId('turma_id')->constrained('turmas')->restrictOnDelete();
            $table->dateTime('data')->useCurrent();
            $table->string('status')->default('Ativa');

            $table->index(['unidade_id', 'status']);
            $table->index(['turma_id', 'status']);
        });

        Schema::create('matricula_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('turma_anterior_id')->nullable()->constrained('turmas')->restrictOnDelete();
            $table->foreignId('turma_id')->nullable()->constrained('turmas')->restrictOnDelete();
            $table->string('acao');
            $table->string('status_anterior')->nullable();
            $table->string('status')->nullable();
            $table->json('detalhes')->nullable();
            $table->timestamps();

            $table->index(['matricula_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_audits');

        Schema::table('matriculas', function (Blueprint $table) {
            $table->dropIndex(['unidade_id', 'status']);
            $table->dropIndex(['turma_id', 'status']);
            $table->dropUnique(['visita_id']);
            $table->dropConstrainedForeignId('unidade_id');
            $table->dropConstrainedForeignId('lead_id');
            $table->dropConstrainedForeignId('visita_id');
            $table->dropConstrainedForeignId('turma_id');
            $table->dropColumn(['data', 'status']);
        });
    }
};
