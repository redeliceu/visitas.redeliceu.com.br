<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->dropUnique(['lead_id']);
            $table->string('fonte')->default('Manual')->after('status');
            $table->index(['lead_id', 'fonte', 'data']);
        });
    }

    public function down(): void
    {
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->dropIndex(['lead_id', 'fonte', 'data']);
            $table->dropColumn('fonte');
            $table->unique('lead_id');
        });
    }
};
