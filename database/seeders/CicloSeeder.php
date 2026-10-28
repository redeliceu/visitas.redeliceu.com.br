<?php

namespace Database\Seeders;

use App\Models\Ciclo;
use Illuminate\Database\Seeder;

class CicloSeeder extends Seeder
{
    public function run(): void
    {
        $ciclos = [
            'Educação Infantil',
            'Ensino Fundamental I',
            'Ensino Fundamental II',
            'Ensino Médio',
        ];

        foreach ($ciclos as $ciclo) {
            Ciclo::updateOrCreate(
                ['nome' => $ciclo],
                ['ativo' => true]
            );
        }
    }
}
