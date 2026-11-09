<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            // Encrypted with the application key; the ciphertext needs a text column.
            $table->text('sellflux_token')->nullable()->after('ativo');
            $table->string('sellflux_project_name')->nullable()->after('sellflux_token');
            $table->dateTime('sellflux_token_updated_at')->nullable()->after('sellflux_project_name');
            $table->foreignId('sellflux_token_updated_by')
                ->nullable()
                ->after('sellflux_token_updated_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sellflux_token_updated_by');
            $table->dropColumn([
                'sellflux_token',
                'sellflux_project_name',
                'sellflux_token_updated_at',
            ]);
        });
    }
};
