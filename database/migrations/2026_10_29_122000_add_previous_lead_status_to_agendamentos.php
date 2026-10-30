<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->foreignId('lead_status_anterior_id')
                ->nullable()
                ->after('lead_id')
                ->constrained('lead_status')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_status_anterior_id');
        });
    }
};
