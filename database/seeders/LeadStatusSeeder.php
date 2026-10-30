<?php

namespace Database\Seeders;

use App\Models\LeadStatus;
use Illuminate\Database\Seeder;

class LeadStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            'Novo',
            'Atendendo',
            'Em Negociação',
            'Agendado',
            'Compareceu',
            'Follow-up',
            'Perdido',
            'Sem Interesse',
            'Não Responde',
            'Rematrícula',
            'Matriculado',
            'Aluno',
        ];

        foreach ($statuses as $index => $status) {
            LeadStatus::updateOrCreate(
                ['nome' => $status],
                [
                    'ativo' => true,
                    'ordem' => $index + 1,
                ]
            );
        }
    }
}
