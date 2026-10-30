<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendedores', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('unidade_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->unique(['user_id', 'unidade_id']);
        });

        $now = now();
        $users = DB::table('users')
            ->where('role', 'atendimento')
            ->get(['id', 'name', 'ativo']);

        foreach ($users as $user) {
            $unitIds = DB::table('unidade_user')
                ->join('unidades', 'unidades.id', '=', 'unidade_user.unidade_id')
                ->where('unidade_user.user_id', $user->id)
                ->where('unidades.ativo', true)
                ->pluck('unidade_user.unidade_id');

            foreach ($unitIds as $unitId) {
                $matchingSellers = DB::table('vendedores')
                    ->where('unidade_id', $unitId)
                    ->whereNull('user_id')
                    ->where('nome', $user->name)
                    ->limit(2)
                    ->get(['id']);

                if ($matchingSellers->count() === 1) {
                    DB::table('vendedores')
                        ->where('id', $matchingSellers->first()->id)
                        ->update([
                            'user_id' => $user->id,
                            'ativo' => $user->ativo,
                            'updated_at' => $now,
                        ]);
                } else {
                    DB::table('vendedores')->insert([
                        'unidade_id' => $unitId,
                        'user_id' => $user->id,
                        'nome' => $user->name,
                        'ativo' => $user->ativo,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('vendedores', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'unidade_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
