<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_status', function (Blueprint $table) {
            $table->id();

            $table->string('nome');
            $table->string('descricao')->nullable();

            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('ordem')->default(0);

            $table->timestamps();

            $table->unique('nome');
            $table->index(['ativo', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_status');
    }
};
