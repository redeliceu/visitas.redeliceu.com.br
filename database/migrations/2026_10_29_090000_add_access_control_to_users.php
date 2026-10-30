<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('atendimento');
            $table->boolean('ativo')->default(true);
        });

        Schema::create('unidade_user', function (Blueprint $table) {
            $table->foreignId('unidade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['unidade_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidade_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'ativo']);
        });
    }
};
