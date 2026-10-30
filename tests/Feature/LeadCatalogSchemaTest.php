<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeadCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_schema_keeps_prototype_channels_and_normalized_catalogs(): void
    {
        $this->assertTrue(Schema::hasColumns('leads', [
            'telefone',
            'telefone_secundario',
            'idade',
            'nascimento',
            'serie_id',
            'ciclo_id',
            'turno_id',
            'canal_contato',
            'canal_mkt',
            'status_id',
            'origem',
        ]));
    }

    public function test_lead_can_store_prototype_contact_fields(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Leads']);

        $lead = Lead::query()->create([
            'unidade_id' => $unit->id,
            'responsavel' => 'Responsável de teste',
            'telefone' => '11999990000',
            'telefone_secundario' => '11888880000',
            'aluno' => 'Estudante de teste',
            'idade' => 8,
            'canal_mkt' => 'Campanha escolar',
            'canal_contato' => 'WhatsApp',
        ]);

        $this->assertSame(8, $lead->fresh()->idade);
        $this->assertSame('Campanha escolar', $lead->fresh()->canal_mkt);
        $this->assertSame('11888880000', $lead->fresh()->telefone_secundario);
    }

    public function test_lead_status_seeder_includes_prototype_states_and_is_idempotent(): void
    {
        Artisan::call('db:seed', ['--class' => 'LeadStatusSeeder']);
        Artisan::call('db:seed', ['--class' => 'LeadStatusSeeder']);

        $names = LeadStatus::query()->orderBy('ordem')->pluck('nome')->all();

        foreach ([
            'Novo',
            'Atendendo',
            'Em Negociação',
            'Agendado',
            'Compareceu',
            'Follow-up',
            'Perdido',
            'Sem Interesse',
            'Matriculado',
        ] as $status) {
            $this->assertContains($status, $names);
        }

        $this->assertCount(count(array_unique($names)), $names);
        $this->assertSame(range(1, count($names)), LeadStatus::query()
            ->orderBy('ordem')
            ->pluck('ordem')
            ->all());
    }
}
