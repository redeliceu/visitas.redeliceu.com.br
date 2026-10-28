<?php

namespace Database\Seeders;

use App\Models\Turno;
use Illuminate\Database\Seeder;

class TurnoSeeder extends Seeder
{
    public function run(): void
    {
        $turnos = [
            'Manhã',
            'Tarde',
            'Manhã + Contraturno',
            'Tarde + Contraturno',
            'Integral'
        ];

        foreach ($turnos as $turno) {
            Turno::updateOrCreate(
                ['nome' => $turno],
                ['ativo' => true]
            );
        }
    }
}
