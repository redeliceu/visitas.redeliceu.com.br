<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('turmas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('unidade_id')
                ->constrained('unidades')
                ->restrictOnDelete();

            $table->string('nome');
            $table->string('periodo');
            $table->unsignedInteger('capacidade')->default(25);
            $table->string('sala')->nullable()->default('');

            $table->timestamps();

            $table->index(['unidade_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turmas');
    }
};
