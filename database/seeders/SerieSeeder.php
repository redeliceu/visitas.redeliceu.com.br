<?php

namespace Database\Seeders;

use App\Models\Serie;
use Illuminate\Database\Seeder;

class SerieSeeder extends Seeder
{
    public function run(): void
    {
        $series = [
            'Berçário',
            'Berçário I',
            'Maternal',
            'Maternal I',
            'Pré I',
            'Pré II',
            '1 ano',
            '2 ano',
            '3 ano',
            '4 ano',
            '5 ano',
            '6 ano',
            '7 ano',
            '8 ano',
            '9 ano',
            '1 Série',
            '2 Série',
            '3 Série',
            'Prontidão',
        ];

        foreach ($series as $serie) {
            Serie::updateOrCreate(
                ['nome' => $serie],
                ['ativo' => true]
            );
        }
    }
}
