<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up notes linking a seller to a lead (D-028).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Seller responsible for the lead when the note was written; empty for queue leads annotated by gestor/master.
            $table->foreignId('vendedor_id')->nullable()->constrained('vendedores')->nullOnDelete();
            // Account that wrote the note.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('texto', 2000);
            $table->dateTime('proximo_retorno')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_follow_ups');
    }
};
