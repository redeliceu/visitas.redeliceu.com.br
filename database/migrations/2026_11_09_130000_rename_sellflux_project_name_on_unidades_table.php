<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Project tokens can't read the project's name on SellFlux, only its id.
     */
    public function up(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            $table->renameColumn('sellflux_project_name', 'sellflux_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            $table->renameColumn('sellflux_project_id', 'sellflux_project_name');
        });
    }
};
