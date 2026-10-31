<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\Ciclo;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Serie;
use App\Models\Turno;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class LegacyLeadImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
        'STATUS',
        'Data',
        'Atendente',
        'Canal MKT',
        'Canal Contato',
        'Nome Responsável',
        'Celular 1 Responsável (Mãe)',
        'E-Mail Responsável',
        'Nome do Aluno',
        'Data Nascimento',
        'Idade',
        'Ciclo',
        'Turma',
        'Turno',
        'Data Agendamento',
        'Hora Agendada',
        'Data Visita',
        'Hora Visita',
        'Data Retorno',
        'Desconto (%)',
        'Celular 2 Responsável (Pai)',
        'Data Matricula',
        'Comentário',
    ];

    public function test_master_imports_historical_leads_and_reimport_updates_without_duplicates(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Centro']);
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'REGINA',
        ]);
        $status = LeadStatus::query()->create(['nome' => 'Atendendo']);
        $cycle = Ciclo::query()->create(['nome' => 'Educação Infantil']);
        $series = Serie::query()->create(['nome' => '1 ano']);
        $turn = Turno::query()->create(['nome' => 'Manhã']);

        $firstImport = $this->actingAs($master)
            ->from(route('admin.legacy-import.index'))
            ->post(route('admin.legacy-import.store'), [
                'unidade_id' => $unit->id,
                'arquivo' => $this->workbook([
                    $this->leadRow(),
                ]),
            ]);

        $firstImport->assertRedirect(route('admin.legacy-import.index'))
            ->assertSessionHas('success');

        $lead = Lead::query()->sole();
        $this->assertSame('2024-01-02 00:00:00', $lead->getRawOriginal('created_at'));
        $this->assertSame('2024-01-02 00:00:00', $lead->getRawOriginal('data'));
        $this->assertSame($seller->id, $lead->vendedor_id);
        $this->assertSame($status->id, $lead->status_id);
        $this->assertSame($cycle->id, $lead->ciclo_id);
        $this->assertSame($series->id, $lead->serie_id);
        $this->assertSame($turn->id, $lead->turno_id);
        $this->assertSame(7, $lead->idade);
        $this->assertSame('50.00', $lead->desconto);
        $this->assertSame('Primeiro contato', $lead->observacoes);

        $appointment = Agendamento::query()->sole();
        $this->assertSame('12:00:00', $appointment->horario);
        $this->assertSame('Compareceu', $appointment->status);

        $visit = Visita::query()->sole();
        $this->assertSame('Em Atendimento', $visit->status);
        $this->assertSame('Importação histórica', $visit->fonte);
        $this->assertSame($appointment->id, $visit->agendamento_id);
        $this->assertSame($visit->id, $appointment->visita_id);

        $updatedRow = $this->leadRow([
            'Data' => '2024-02-02',
            'Atendente' => '',
            'STATUS' => '',
            'Celular 1 Responsável (Mãe)' => '',
            'Celular 2 Responsável (Pai)' => '11988887777',
            'Ciclo' => '',
            'Turma' => '',
            'Turno' => '',
            'Data Agendamento' => '',
            'Hora Agendada' => '',
            'Data Visita' => '',
            'Hora Visita' => '',
            'Data Retorno' => '',
            'Desconto (%)' => '',
            'Data Matricula' => '',
            'Comentário' => '',
        ]);

        $this->actingAs($master)
            ->from(route('admin.legacy-import.index'))
            ->post(route('admin.legacy-import.store'), [
                'unidade_id' => $unit->id,
                'arquivo' => $this->workbook([$updatedRow]),
            ])
            ->assertRedirect(route('admin.legacy-import.index'))
            ->assertSessionHas('success');

        $lead->refresh();
        $this->assertDatabaseCount('leads', 1);
        $this->assertSame('2024-01-02 00:00:00', $lead->getRawOriginal('created_at'));
        $this->assertSame('2024-02-02 00:00:00', $lead->getRawOriginal('data'));
        $this->assertSame('11988887777', $lead->telefone);
        $this->assertSame('11988887777', $lead->telefone_secundario);
        $this->assertSame('Primeiro contato', $lead->observacoes);
        $this->assertSame($status->id, $lead->status_id);
        $this->assertSame($series->id, $lead->serie_id);
        $this->assertDatabaseCount('vendedores', 1);
        $this->assertDatabaseCount('agendamentos', 1);
        $this->assertDatabaseCount('visitas', 1);
    }

    public function test_master_creates_missing_sellers_and_skips_rows_without_identity_or_date(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Leste']);
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        LeadStatus::query()->create(['nome' => 'Novo']);

        $contactless = $this->leadRow([
            'STATUS' => 'Novo',
            'Celular 1 Responsável (Mãe)' => '',
            'Celular 2 Responsável (Pai)' => '',
            'E-Mail Responsável' => '',
        ]);
        $statusOnly = array_fill_keys(self::HEADERS, '');
        $statusOnly['STATUS'] = 'Novo';

        $this->actingAs($master)
            ->from(route('admin.legacy-import.index'))
            ->post(route('admin.legacy-import.store'), [
                'unidade_id' => $unit->id,
                'arquivo' => $this->workbook([
                    $this->leadRow([
                        'Atendente' => 'Vendedor Novo',
                        'STATUS' => 'Novo',
                        'Idade' => '8 ano',
                        'Ciclo' => '',
                        'Turma' => 'Variação sem série',
                        'Turno' => '',
                        'Data Agendamento' => '',
                        'Hora Agendada' => '',
                        'Data Visita' => '',
                        'Hora Visita' => '',
                        'Data Retorno' => 'aguardando retorno',
                        'Comentário' => 'Comentário da planilha',
                    ]),
                    $contactless,
                    $statusOnly,
                ]),
            ])
            ->assertRedirect(route('admin.legacy-import.index'))
            ->assertSessionHas('success', fn (mixed $message): bool => is_string($message)
                && str_contains($message, '1 vendedor(es) criado(s)')
                && str_contains($message, '2 linha(s) ignorada(s)')
                && str_contains($message, 'ficaram sem série correspondente'));

        $this->assertDatabaseCount('leads', 1);
        $lead = Lead::query()->sole();
        $this->assertNull($lead->idade);
        $this->assertNull($lead->serie_id);
        $this->assertStringContainsString('8 ano', $lead->observacoes);
        $this->assertStringContainsString('aguardando retorno', $lead->observacoes);
        $this->assertStringContainsString('Comentário da planilha', $lead->observacoes);
        $this->assertDatabaseHas('vendedores', [
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Novo',
        ]);
    }

    public function test_excel_serial_date_is_stored_consistently_as_lead_data_and_created_at(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Centro']);
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        LeadStatus::query()->create(['nome' => 'Atendendo']);
        Ciclo::query()->create(['nome' => 'Educação Infantil']);
        Turno::query()->create(['nome' => 'Manhã']);
        $row = $this->leadRow([
            'Data' => ExcelDate::PHPToExcel(new \DateTimeImmutable('2020-10-30 00:00:00')),
        ]);

        $this->actingAs($master)
            ->from(route('admin.legacy-import.index'))
            ->post(route('admin.legacy-import.store'), [
                'unidade_id' => $unit->id,
                'arquivo' => $this->workbook([$row]),
            ])
            ->assertRedirect(route('admin.legacy-import.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('import_report', fn (array $report): bool => str_contains(
                $report['markdown'],
                'Leads criadas: 1',
            ) && str_contains($report['markdown'], 'Nenhuma linha com falha.'));

        $this->actingAs($master)
            ->get(route('admin.legacy-import.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.importReport.filename', fn (string $filename): bool => str_ends_with($filename, '.md'))
                ->where('flash.importReport.markdown', fn (string $markdown): bool => str_contains(
                    $markdown,
                    'Unidade: Unidade Centro',
                )));

        $this->actingAs($master)
            ->get(route('admin.legacy-import.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.importReport', null));

        $lead = Lead::query()->sole();
        $this->assertSame('2020-10-30 00:00:00', $lead->getRawOriginal('data'));
        $this->assertSame('2020-10-30 00:00:00', $lead->getRawOriginal('created_at'));
    }

    public function test_invalid_rows_are_skipped_while_valid_rows_import_and_failures_are_reported(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Centro']);
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        LeadStatus::query()->create(['nome' => 'Atendendo']);
        Ciclo::query()->create(['nome' => 'Educação Infantil']);
        Turno::query()->create(['nome' => 'Manhã']);

        $this->actingAs($master)
            ->from(route('admin.legacy-import.index'))
            ->post(route('admin.legacy-import.store'), [
                'unidade_id' => $unit->id,
                'arquivo' => $this->workbook([
                    $this->leadRow(['Data' => '30/10/202']),
                    $this->leadRow(['Ciclo' => 'Ciclo desconhecido']),
                    $this->leadRow(),
                ]),
            ])
            ->assertRedirect(route('admin.legacy-import.index'))
            ->assertSessionHas('warning', fn (string $warning): bool => str_contains($warning, 'Linha 2:')
                && str_contains($warning, '30/10/0202')
                && str_contains($warning, 'Linha 3:')
                && str_contains($warning, 'Ciclo desconhecido'))
            ->assertSessionHas('import_report', fn (array $report): bool => str_contains(
                $report['markdown'],
                '- Linha 2: Data "30/10/0202"',
            ) && str_contains(
                $report['markdown'],
                '- Linha 3: ciclo "Ciclo desconhecido"',
            ));

        $this->assertDatabaseCount('leads', 1);
        $lead = Lead::query()->sole();
        $this->assertSame('2024-01-02 00:00:00', $lead->getRawOriginal('data'));
    }

    public function test_only_master_can_open_or_submit_the_historical_import(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Oeste']);
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);

        $this->actingAs($gestor)
            ->get(route('admin.legacy-import.index'))
            ->assertForbidden();

        $this->actingAs($gestor)
            ->post(route('admin.legacy-import.store'), [
                'unidade_id' => $unit->id,
                'arquivo' => $this->workbook([$this->leadRow()]),
            ])
            ->assertForbidden();

        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $this->actingAs($master)
            ->get(route('admin.legacy-import.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/legacy-import')
                ->where('units.0.id', $unit->id));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function leadRow(array $overrides = []): array
    {
        return array_replace(array_fill_keys(self::HEADERS, ''), [
            'STATUS' => 'Atendendo',
            'Data' => '2024-01-02',
            'Atendente' => 'Regina',
            'Canal MKT' => 'Google',
            'Canal Contato' => 'WhatsApp',
            'Nome Responsável' => 'Maria de Souza',
            'Celular 1 Responsável (Mãe)' => '11988887777',
            'E-Mail Responsável' => 'maria@example.com',
            'Nome do Aluno' => 'Pedro de Souza',
            'Data Nascimento' => '2017-05-10',
            'Idade' => '7 anos',
            'Ciclo' => 'Educação Infanti',
            'Turma' => '1º ano',
            'Turno' => 'Manhã',
            'Data Agendamento' => '2024-01-05',
            'Hora Agendada' => '',
            'Data Visita' => '2024-01-05',
            'Hora Visita' => '',
            'Data Retorno' => '2024-02-01',
            'Desconto (%)' => '50%',
            'Celular 2 Responsável (Pai)' => '',
            'Data Matricula' => '2024-02-10',
            'Comentário' => 'Primeiro contato',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach (self::HEADERS as $columnIndex => $header) {
                $sheet->setCellValue(
                    [$columnIndex + 1, $rowIndex + 2],
                    $row[$header] ?? '',
                );
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'legacy-import-');
        if ($path === false) {
            throw new \RuntimeException('Não foi possível criar a planilha de teste.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);
            $contents = file_get_contents($path);
        } finally {
            unlink($path);
            $spreadsheet->disconnectWorksheets();
        }

        if ($contents === false) {
            throw new \RuntimeException('Não foi possível ler a planilha de teste.');
        }

        return UploadedFile::fake()->createWithContent('legacy.xlsx', $contents);
    }
}
