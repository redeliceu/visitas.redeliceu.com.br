<?php

namespace App\Support;

use App\Models\Agendamento;
use App\Models\Ciclo;
use App\Models\Lead;
use App\Models\LeadHistory;
use App\Models\LeadStatus;
use App\Models\Serie;
use App\Models\Turno;
use App\Models\Unidade;
use App\Models\Vendedor;
use App\Models\Visita;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LegacyLeadImportService
{
    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'STATUS' => 'status',
        'Data' => 'data',
        'Atendente' => 'seller',
        'Canal MKT' => 'canal_mkt',
        'Canal Contato' => 'canal_contato',
        'Nome Responsável' => 'responsavel',
        'Celular 1 Responsável (Mãe)' => 'telefone',
        'E-Mail Responsável' => 'email',
        'Nome do Aluno' => 'aluno',
        'Data Nascimento' => 'nascimento',
        'Idade' => 'idade',
        'Ciclo' => 'ciclo',
        'Turma' => 'serie',
        'Turno' => 'turno',
        'Data Agendamento' => 'appointment_date',
        'Hora Agendada' => 'appointment_time',
        'Data Visita' => 'visit_date',
        'Hora Visita' => 'visit_time',
        'Data Retorno' => 'return_date',
        'Desconto (%)' => 'desconto',
        'Celular 2 Responsável (Pai)' => 'telefone_secundario',
        'Data Matricula' => 'data_matricula',
        'Comentário' => 'comment',
    ];

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     sellers_created: int,
     *     appointments_created: int,
     *     appointments_updated: int,
     *     visits_created: int,
     *     visits_updated: int,
     *     imported_without_date: int,
     *     imported_without_contact: int,
     *     failed_rows: list<string>,
     *     raw_value_warnings: list<string>,
     *     unmatched_series: array<string, int>
     * }
     */
    public function import(UploadedFile $file, int $unitId, ?int $userId = null): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não foi possível ler o arquivo enviado.',
            ]);
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (ReaderException $exception) {
            throw ValidationException::withMessages([
                'arquivo' => 'O arquivo não é uma planilha Excel válida.',
            ]);
        }

        $worksheet = $spreadsheet->getActiveSheet();
        $columns = $this->columnIndexes($worksheet->getHighestDataColumn());
        $headerIndexes = $this->headerIndexes($worksheet, $columns);
        $catalogs = $this->catalogs();
        $rows = [];
        $failedRows = [];
        $rawValueWarnings = [];
        $unmatchedSeries = [];

        for ($rowNumber = 2; $rowNumber <= $worksheet->getHighestDataRow(); $rowNumber++) {
            $cells = [];
            $hasData = false;

            foreach ($headerIndexes as $key => $columnIndex) {
                $cell = $worksheet->getCell([$columnIndex + 1, $rowNumber]);
                $value = $cell->getValue();
                $display = trim((string) $cell->getFormattedValue());
                $cells[$key] = ['value' => $value, 'display' => $display];
                $hasData = $hasData || ! $this->isBlank($value);
            }

            if (! $hasData) {
                continue;
            }

            $phone = $this->cellString($cells['telefone']);
            $secondaryPhone = $this->cellString($cells['telefone_secundario']);
            $email = $this->cellString($cells['email']);
            $phones = [];
            foreach ([
                $this->normalizePhone($phone),
                $this->normalizePhone($secondaryPhone),
            ] as $normalizedPhone) {
                if ($normalizedPhone !== null && ! in_array($normalizedPhone, $phones, true)) {
                    $phones[] = $normalizedPhone;
                }
            }

            try {
                $rowUnmatchedSeries = [];
                $rowWarnings = [];
                $rows[] = $this->parseRow(
                    $cells,
                    $rowNumber,
                    $phone,
                    $secondaryPhone,
                    $email,
                    $phones,
                    $catalogs,
                    $rowUnmatchedSeries,
                    $rowWarnings,
                );
                foreach ($rowUnmatchedSeries as $seriesName => $count) {
                    $unmatchedSeries[$seriesName] = ($unmatchedSeries[$seriesName] ?? 0) + $count;
                }
                foreach ($rowWarnings as $warning) {
                    $rawValueWarnings[] = "Linha {$rowNumber}: {$warning}";
                }
            } catch (InvalidArgumentException $exception) {
                $failedRows[] = "Linha {$rowNumber}: {$exception->getMessage()}";
            }
        }

        return DB::transaction(function () use (
            $rows,
            $unitId,
            $userId,
            $failedRows,
            $rawValueWarnings,
            $unmatchedSeries,
        ): array {
            $unit = Unidade::query()->whereKey($unitId)->lockForUpdate()->firstOrFail();

            $sellers = $this->sellerIndex($unitId);
            $leads = Lead::query()->where('unidade_id', $unitId)->get();
            $leadsById = $leads->keyBy('id');
            $phoneIndex = [];
            $emailIndex = [];
            $phoneContactIndex = [];
            $emailContactIndex = [];

            foreach ($leads as $lead) {
                $this->addLeadToIndexes(
                    $lead,
                    $phoneIndex,
                    $emailIndex,
                    $phoneContactIndex,
                    $emailContactIndex,
                );
            }

            $result = [
                'created' => 0,
                'updated' => 0,
                'sellers_created' => 0,
                'appointments_created' => 0,
                'appointments_updated' => 0,
                'visits_created' => 0,
                'visits_updated' => 0,
                'imported_without_date' => 0,
                'imported_without_contact' => 0,
                'failed_rows' => $failedRows,
                'raw_value_warnings' => $rawValueWarnings,
                'unmatched_series' => $unmatchedSeries,
            ];

            foreach ($rows as $row) {
                $ambiguous = false;
                $lead = $this->findLead(
                    $row,
                    $leadsById,
                    $phoneIndex,
                    $emailIndex,
                    $phoneContactIndex,
                    $emailContactIndex,
                    $ambiguous,
                );
                if ($ambiguous) {
                    $result['failed_rows'][] = "Linha {$row['row_number']}: o contato corresponde a mais de uma lead; a linha precisa de revisão manual.";

                    continue;
                }

                $isNew = $lead === null;
                $historicalDate = $row['data']?->format('Y-m-d H:i:s');
                $original = [];

                if ($lead === null) {
                    $lead = new Lead;
                    $lead->unidade()->associate($unit);
                    $lead->origem = 'Importação histórica';
                    $lead->data = $historicalDate;
                    if ($historicalDate !== null) {
                        $lead->setCreatedAt($historicalDate);
                    }

                    $defaults = array_fill_keys([
                        'vendedor_id',
                        'responsavel',
                        'telefone',
                        'telefone_secundario',
                        'email',
                        'aluno',
                        'idade',
                        'nascimento',
                        'serie_id',
                        'ciclo_id',
                        'turno_id',
                        'canal_contato',
                        'canal_mkt',
                        'status_id',
                        'retorno_data',
                        'observacoes',
                        'data_matricula',
                        'desconto',
                    ], null);
                    $lead->fill(array_replace($defaults, $row['fields']));
                } else {
                    $original = $lead->getRawOriginal();
                    $this->removeLeadFromIndexes(
                        $lead,
                        $phoneIndex,
                        $emailIndex,
                        $phoneContactIndex,
                        $emailContactIndex,
                    );
                    if ($historicalDate !== null) {
                        $lead->data = $historicalDate;
                    }
                    $fields = $row['fields'];
                    if (isset($fields['observacoes']) && $lead->observacoes !== null) {
                        $fields['observacoes'] = implode("\n", array_values(array_unique(array_filter([
                            ...explode("\n", $lead->observacoes),
                            ...explode("\n", $fields['observacoes']),
                        ]))));
                    }
                    $lead->fill($fields);
                }

                if ($row['seller_name'] !== '') {
                    $seller = $this->resolveSeller(
                        $row['seller_name'],
                        $unitId,
                        $sellers,
                        $result['sellers_created'],
                    );
                    $lead->vendedor_id = $seller->id;
                }

                $lead->save();

                if ($isNew) {
                    $result['created']++;
                } else {
                    $result['updated']++;
                }

                $leadsById->put($lead->id, $lead);
                $this->addLeadToIndexes(
                    $lead,
                    $phoneIndex,
                    $emailIndex,
                    $phoneContactIndex,
                    $emailContactIndex,
                );

                if (! $isNew && $lead->wasChanged()) {
                    $this->recordImportedLeadChanges($lead, $original, $userId);
                }

                $result['imported_without_date'] += $row['data'] === null ? 1 : 0;
                $result['imported_without_contact'] += $row['phones'] === [] && $row['email_key'] === '' ? 1 : 0;

                $appointment = null;

                if ($row['appointment_date'] instanceof CarbonImmutable) {
                    $appointment = $lead->agendamentos()
                        ->where('fonte', 'Importação histórica')
                        ->whereDate('data', $row['appointment_date']->toDateString())
                        ->first();
                    $appointmentWasNew = $appointment === null;
                    $appointment ??= new Agendamento;
                    $appointment->fill([
                        'unidade_id' => $unitId,
                        'lead_id' => $lead->id,
                        'vendedor_id' => $lead->vendedor_id,
                        'data' => $row['appointment_date'],
                        'horario' => $row['appointment_time'] ?? '12:00:00',
                        'responsavel' => $lead->responsavel ?: 'Responsável não informado',
                        'telefone' => $lead->telefone,
                        'aluno' => $lead->aluno,
                        'observacoes' => $row['observations'] ?? $appointment->observacoes,
                        'status' => $row['visit_date'] instanceof CarbonImmutable
                            ? 'Compareceu'
                            : 'Agendado',
                        'fonte' => 'Importação histórica',
                    ]);
                    $appointment->save();

                    if ($appointmentWasNew) {
                        $result['appointments_created']++;
                    } else {
                        $result['appointments_updated']++;
                    }
                }

                if ($row['visit_date'] instanceof CarbonImmutable) {
                    $visit = Visita::query()
                        ->where('lead_id', $lead->id)
                        ->whereDate('data', $row['visit_date']->toDateString())
                        ->where('fonte', 'Importação histórica')
                        ->first();
                    $visitWasNew = $visit === null;
                    $visit ??= new Visita;
                    $visitStatus = $this->visitStatus($row['status_name']);
                    $linkAppointment = $appointment !== null;

                    if (
                        $appointment?->visita_id !== null
                        && $appointment->visita_id !== $visit->id
                    ) {
                        $previousVisit = Visita::query()->find($appointment->visita_id);

                        if ($previousVisit?->fonte === 'Importação histórica') {
                            $previousVisit->agendamento_id = null;
                            $previousVisit->save();
                        } else {
                            $linkAppointment = false;
                        }
                    }

                    $visit->fill([
                        'unidade_id' => $unitId,
                        'lead_id' => $lead->id,
                        'vendedor_id' => $lead->vendedor_id,
                        'data' => $row['visit_date'],
                        'horario' => $row['visit_time'] ?? $visit->horario,
                        'responsavel' => $lead->responsavel ?: 'Responsável não informado',
                        'telefone' => $lead->telefone,
                        'telefone2' => $lead->telefone_secundario,
                        'aluno' => $lead->aluno,
                        'idade' => $lead->idade,
                        'serie' => $row['serie_name'] ?? $visit->serie,
                        'situacao' => $visitStatus,
                        'fonte' => 'Importação histórica',
                        'status' => $visitStatus,
                        'observacoes' => $row['observations'] ?? $visit->observacoes,
                        'retorno_data' => $row['return_date'] ?? $visit->retorno_data,
                    ]);

                    if ($linkAppointment) {
                        $visit->agendamento_id = $appointment->id;
                    }

                    $visit->save();

                    if ($linkAppointment) {
                        $appointment->visita_id = $visit->id;
                        $appointment->save();
                    }

                    if ($visitWasNew) {
                        $result['visits_created']++;
                    } else {
                        $result['visits_updated']++;
                    }
                }
            }

            return $result;
        });
    }

    /**
     * @return list<int>
     */
    private function columnIndexes(string $highestColumn): array
    {
        $lastColumn = Coordinate::columnIndexFromString($highestColumn);

        return range(0, $lastColumn - 1);
    }

    /**
     * @param  list<int>  $columns
     * @return array<string, int>
     */
    private function headerIndexes(Worksheet $worksheet, array $columns): array
    {
        $headers = [];

        foreach ($columns as $columnIndex) {
            $value = (string) $worksheet->getCell([$columnIndex + 1, 1])->getFormattedValue();

            if ($value !== '') {
                $headers[$this->normalizeLabel($value)] = $columnIndex;
            }
        }

        $indexes = [];

        foreach (self::HEADERS as $header => $key) {
            $normalized = $this->normalizeLabel($header);

            if (! array_key_exists($normalized, $headers)) {
                throw ValidationException::withMessages([
                    'arquivo' => "A planilha não contém a coluna obrigatória \"{$header}\".",
                ]);
            }

            $indexes[$key] = $headers[$normalized];
        }

        return $indexes;
    }

    /**
     * @return array{
     *     statuses: array<string, int>,
     *     cycles: array<string, int>,
     *     series: array<string, array{id: int, name: string}>,
     *     turns: array<string, int>
     * }
     */
    private function catalogs(): array
    {
        $statuses = [];
        foreach (LeadStatus::query()->get(['id', 'nome']) as $status) {
            $statuses[$this->normalizeLabel($status->nome)] = $status->id;
        }

        $cycles = [];
        foreach (Ciclo::query()->get(['id', 'nome']) as $cycle) {
            $cycles[$this->normalizeLabel($cycle->nome)] = $cycle->id;
        }

        $series = [];
        foreach (Serie::query()->get(['id', 'nome']) as $serie) {
            $series[$this->normalizeLabel($serie->nome)] = [
                'id' => $serie->id,
                'name' => $serie->nome,
            ];
        }

        $turns = [];
        foreach (Turno::query()->get(['id', 'nome']) as $turn) {
            $turns[$this->normalizeLabel($turn->nome)] = $turn->id;
        }

        return [
            'statuses' => $statuses,
            'cycles' => $cycles,
            'series' => $series,
            'turns' => $turns,
        ];
    }

    /**
     * @param  array<string, array{value: mixed, display: string}>  $cells
     * @param  array{
     *     statuses: array<string, int>,
     *     cycles: array<string, int>,
     *     series: array<string, array{id: int, name: string}>,
     *     turns: array<string, int>
     * }  $catalogs
     * @param  array<string, int>  $unmatchedSeries
     * @param  list<string>  $phones
     * @param  list<string>  $rowWarnings
     * @return array{
     *     row_number: int,
     *     data: CarbonImmutable|null,
     *     fields: array<string, mixed>,
     *     phones: list<string>,
     *     student_key: string,
     *     email_key: string,
     *     seller_name: string,
     *     appointment_date: CarbonImmutable|null,
     *     appointment_time: string|null,
     *     visit_date: CarbonImmutable|null,
     *     visit_time: string|null,
     *     return_date: CarbonImmutable|null,
     *     observations: string|null,
     *     status_name: string,
     *     serie_name: string|null
     * }
     */
    private function parseRow(
        array $cells,
        int $rowNumber,
        string $phone,
        string $secondaryPhone,
        string $email,
        array $phones,
        array $catalogs,
        array &$unmatchedSeries,
        array &$rowWarnings,
    ): array {
        $notes = [];
        $data = $this->parseDateCell($cells['data'], 'Data', $notes, $rowWarnings);
        $fields = $data === null ? [] : ['data' => $data];
        $sellerName = $this->cellString($cells['seller']);
        $aluno = $this->cellString($cells['aluno']);
        $statusName = $this->cellString($cells['status']);
        $statusKey = $this->normalizeLabel($statusName);

        if ($statusName !== '') {
            if (! isset($catalogs['statuses'][$statusKey])) {
                $this->preserveUnmappedValue('Status', $cells['status'], $notes, $rowWarnings);
            } else {
                $fields['status_id'] = $catalogs['statuses'][$statusKey];
            }
        }

        $cycleName = $this->cellString($cells['ciclo']);

        if ($cycleName !== '') {
            $cycleKey = $this->normalizeLabel($cycleName);

            if ($cycleKey === $this->normalizeLabel('Educação Infanti')) {
                $cycleKey = $this->normalizeLabel('Educação Infantil');
            }

            if (! isset($catalogs['cycles'][$cycleKey])) {
                $this->preserveUnmappedValue('Ciclo', $cells['ciclo'], $notes, $rowWarnings);
            } else {
                $fields['ciclo_id'] = $catalogs['cycles'][$cycleKey];
            }
        }

        $serieName = $this->cellString($cells['serie']);
        $serieKey = $this->normalizeLabel($serieName);
        $serie = $serieName === '' ? null : ($catalogs['series'][$serieKey] ?? null);

        if ($serieName !== '' && $serie === null) {
            $unmatchedSeries[$serieName] = ($unmatchedSeries[$serieName] ?? 0) + 1;
        } elseif ($serie !== null) {
            $fields['serie_id'] = $serie['id'];
        }

        $turnName = $this->cellString($cells['turno']);

        if ($turnName !== '') {
            $turnKey = $this->normalizeLabel($turnName);

            if (! isset($catalogs['turns'][$turnKey])) {
                $this->preserveUnmappedValue('Turno', $cells['turno'], $notes, $rowWarnings);
            } else {
                $fields['turno_id'] = $catalogs['turns'][$turnKey];
            }
        }

        $this->copyText($fields, $cells, 'responsavel');
        $this->copyText($fields, $cells, 'canal_mkt');
        $this->copyText($fields, $cells, 'canal_contato');
        $this->copyText($fields, $cells, 'email');
        $this->copyText($fields, $cells, 'aluno');

        if ($phone !== '') {
            $fields['telefone'] = $phone;
        }

        if ($secondaryPhone !== '') {
            $fields['telefone_secundario'] = $secondaryPhone;
        }

        $birth = $this->parseDateCell($cells['nascimento'], 'Data Nascimento', $notes, $rowWarnings);
        if ($birth !== null) {
            $fields['nascimento'] = $birth->toDateString();
        }

        $age = $this->cellString($cells['idade']);
        if ($age !== '' && preg_match('/^\s*(\d{1,3})(?:\.0+)?(?:\s+anos)?\s*$/iu', $age, $matches)) {
            $ageValue = (int) $matches[1];
            if ($ageValue > 120) {
                $this->preserveUnmappedValue('Idade', $cells['idade'], $notes, $rowWarnings);
            } else {
                $fields['idade'] = $ageValue;
            }
        } elseif ($age !== '') {
            $this->preserveUnmappedValue('Idade', $cells['idade'], $notes, $rowWarnings);
        }

        $returnDate = $this->parseDateCell($cells['return_date'], 'Data Retorno', $notes, $rowWarnings);
        if ($returnDate !== null) {
            $fields['retorno_data'] = $returnDate;
        }

        $discount = $this->cellString($cells['desconto']);
        if ($discount !== '') {
            try {
                $fields['desconto'] = $this->parseDiscount($discount);
            } catch (InvalidArgumentException) {
                $this->preserveUnmappedValue('Desconto', $cells['desconto'], $notes, $rowWarnings);
            }
        }

        $matriculationDate = $this->parseDateCell($cells['data_matricula'], 'Data Matricula', $notes, $rowWarnings);
        if ($matriculationDate !== null) {
            $fields['data_matricula'] = $matriculationDate;
        }

        $comment = $this->cellString($cells['comment']);
        $appointmentDate = $this->parseDateCell($cells['appointment_date'], 'Data Agendamento', $notes, $rowWarnings);
        $appointmentTime = $this->parseTimeCell($cells['appointment_time'], 'Hora Agendada', $notes, $rowWarnings);
        $visitDate = $this->parseDateCell($cells['visit_date'], 'Data Visita', $notes, $rowWarnings);
        $visitTime = $this->parseTimeCell($cells['visit_time'], 'Hora Visita', $notes, $rowWarnings);
        $observations = implode("\n", array_values(array_unique(array_filter([$comment, ...$notes]))));
        if ($observations !== '') {
            $fields['observacoes'] = $observations;
        }

        return [
            'row_number' => $rowNumber,
            'data' => $data,
            'fields' => $fields,
            'phones' => $phones,
            'student_key' => $this->normalizeIdentity($aluno),
            'email_key' => mb_strtolower(trim($email)),
            'seller_name' => $sellerName,
            'appointment_date' => $appointmentDate,
            'appointment_time' => $appointmentDate === null ? null : ($appointmentTime ?? '12:00:00'),
            'visit_date' => $visitDate,
            'visit_time' => $visitTime,
            'return_date' => $returnDate,
            'observations' => $observations !== '' ? $observations : null,
            'status_name' => $statusName,
            'serie_name' => $serieName !== '' ? $serieName : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, array{value: mixed, display: string}>  $cells
     */
    private function copyText(array &$fields, array $cells, string $key): void
    {
        $value = $this->cellString($cells[$key]);

        if ($value !== '') {
            $fields[$key] = $value;
        }
    }

    /**
     * @param  array{value: mixed, display: string}  $cell
     * @param  list<string>  $notes
     * @param  list<string>  $warnings
     */
    private function parseDateCell(array $cell, string $label, array &$notes, array &$warnings): ?CarbonImmutable
    {
        if ($this->isBlank($cell['value'])) {
            return null;
        }

        try {
            return $this->parseDate($cell['value'], $label);
        } catch (InvalidArgumentException|SpreadsheetException) {
            $this->preserveUnmappedValue($label, $cell, $notes, $warnings);

            return null;
        }
    }

    /**
     * @param  array{value: mixed, display: string}  $cell
     * @param  list<string>  $notes
     * @param  list<string>  $warnings
     */
    private function parseTimeCell(array $cell, string $label, array &$notes, array &$warnings): ?string
    {
        if ($this->isBlank($cell['value'])) {
            return null;
        }

        try {
            return $this->optionalTime($cell['value'], $label);
        } catch (InvalidArgumentException|SpreadsheetException) {
            $this->preserveUnmappedValue($label, $cell, $notes, $warnings);

            return null;
        }
    }

    /**
     * @param  array{value: mixed, display: string}  $cell
     * @param  list<string>  $notes
     * @param  list<string>  $warnings
     */
    private function preserveUnmappedValue(
        string $label,
        array $cell,
        array &$notes,
        array &$warnings,
    ): void {
        $value = $this->cellString($cell);
        if ($value === '') {
            return;
        }

        $notes[] = "{$label} (valor original): {$value}";
        $warnings[] = "{$label}: \"{$value}\" inválida ou sem correspondência; valor original salvo nas observações.";
    }

    /**
     * @param  array{
     *     row_number: int,
     *     phones: list<string>,
     *     student_key: string,
     *     email_key: string
     * }  $row
     * @param  Collection<int, Lead>  $leadsById
     * @param  array<string, list<int>>  $phoneIndex
     * @param  array<string, list<int>>  $emailIndex
     * @param  array<string, list<int>>  $phoneContactIndex
     * @param  array<string, list<int>>  $emailContactIndex
     */
    private function findLead(
        array $row,
        Collection $leadsById,
        array $phoneIndex,
        array $emailIndex,
        array $phoneContactIndex,
        array $emailContactIndex,
        bool &$ambiguous,
    ): ?Lead {
        $studentKey = $row['student_key'];
        $matches = [];

        if ($studentKey !== '') {
            foreach ($row['phones'] as $phone) {
                foreach ($phoneIndex[$phone.'|'.$studentKey] ?? [] as $leadId) {
                    $matches[$leadId] = true;
                }
            }

            if ($row['email_key'] !== '') {
                foreach ($emailIndex[$row['email_key'].'|'.$studentKey] ?? [] as $leadId) {
                    $matches[$leadId] = true;
                }
            }
        } else {
            foreach ($row['phones'] as $phone) {
                foreach ($phoneContactIndex[$phone] ?? [] as $leadId) {
                    $matches[$leadId] = true;
                }
            }

            if ($row['email_key'] !== '') {
                foreach ($emailContactIndex[$row['email_key']] ?? [] as $leadId) {
                    $matches[$leadId] = true;
                }
            }
        }

        if (count($matches) > 1) {
            $ambiguous = true;

            return null;
        }

        if ($matches === []) {
            return null;
        }

        return $leadsById->get((int) array_key_first($matches));
    }

    /**
     * @param  array<string, list<int>>  $phoneIndex
     * @param  array<string, list<int>>  $emailIndex
     * @param  array<string, list<int>>  $phoneContactIndex
     * @param  array<string, list<int>>  $emailContactIndex
     */
    private function addLeadToIndexes(
        Lead $lead,
        array &$phoneIndex,
        array &$emailIndex,
        array &$phoneContactIndex,
        array &$emailContactIndex,
    ): void {
        $studentKey = $this->normalizeIdentity((string) $lead->aluno);

        foreach ([
            $this->normalizePhone((string) $lead->telefone),
            $this->normalizePhone((string) $lead->telefone_secundario),
        ] as $phone) {
            if ($phone !== null) {
                $this->addToIndex($phoneContactIndex, $phone, $lead->id);
                if ($studentKey !== '') {
                    $this->addToIndex($phoneIndex, $phone.'|'.$studentKey, $lead->id);
                }
            }
        }

        $email = mb_strtolower(trim((string) $lead->email));
        if ($email !== '') {
            $this->addToIndex($emailContactIndex, $email, $lead->id);
            if ($studentKey !== '') {
                $this->addToIndex($emailIndex, $email.'|'.$studentKey, $lead->id);
            }
        }
    }

    /**
     * @param  array<string, list<int>>  $phoneIndex
     * @param  array<string, list<int>>  $emailIndex
     * @param  array<string, list<int>>  $phoneContactIndex
     * @param  array<string, list<int>>  $emailContactIndex
     */
    private function removeLeadFromIndexes(
        Lead $lead,
        array &$phoneIndex,
        array &$emailIndex,
        array &$phoneContactIndex,
        array &$emailContactIndex,
    ): void {
        $this->removeFromIndex($phoneIndex, $lead->id);
        $this->removeFromIndex($emailIndex, $lead->id);
        $this->removeFromIndex($phoneContactIndex, $lead->id);
        $this->removeFromIndex($emailContactIndex, $lead->id);
    }

    /**
     * @param  array<string, list<int>>  $index
     */
    private function addToIndex(array &$index, string $key, int $leadId): void
    {
        if (! in_array($leadId, $index[$key] ?? [], true)) {
            $index[$key][] = $leadId;
        }
    }

    /**
     * @param  array<string, list<int>>  $index
     */
    private function removeFromIndex(array &$index, int $leadId): void
    {
        foreach ($index as $key => $ids) {
            $index[$key] = array_values(array_filter(
                $ids,
                fn (int $id): bool => $id !== $leadId,
            ));

            if ($index[$key] === []) {
                unset($index[$key]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $original
     */
    private function recordImportedLeadChanges(Lead $lead, array $original, ?int $userId): void
    {
        $labels = [
            'vendedor_id' => 'Vendedor',
            'data' => 'Data do contato',
            'responsavel' => 'Responsável',
            'telefone' => 'Telefone',
            'telefone_secundario' => 'Telefone secundário',
            'email' => 'E-mail',
            'aluno' => 'Aluno',
            'idade' => 'Idade',
            'nascimento' => 'Data de nascimento',
            'serie_id' => 'Série',
            'ciclo_id' => 'Ciclo',
            'turno_id' => 'Turno',
            'canal_mkt' => 'Canal de marketing',
            'canal_contato' => 'Canal de contato',
            'status_id' => 'Situação',
            'retorno_data' => 'Data de retorno',
            'observacoes' => 'Observações',
            'data_matricula' => 'Data de matrícula',
            'desconto' => 'Desconto',
        ];
        $changes = [];
        $updated = $lead->getChanges();

        foreach ($labels as $field => $label) {
            if (! array_key_exists($field, $updated)) {
                continue;
            }

            $changes[] = [
                'field' => $label,
                'old' => $this->historyValue($field, $original[$field] ?? null),
                'new' => $this->historyValue($field, $updated[$field]),
            ];
        }

        if ($changes !== []) {
            LeadHistory::query()->create([
                'lead_id' => $lead->id,
                'user_id' => $userId,
                'changes' => $changes,
                'created_at' => now(),
            ]);
        }
    }

    private function historyValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            return (string) $value;
        }

        return match ($field) {
            'vendedor_id' => Vendedor::query()->find($value)->nome,
            'serie_id' => Serie::query()->find($value)->nome,
            'ciclo_id' => Ciclo::query()->find($value)->nome,
            'turno_id' => Turno::query()->find($value)->nome,
            'status_id' => LeadStatus::query()->find($value)->nome,
            default => (string) $value,
        };
    }

    /**
     * @return array<string, Vendedor>
     */
    private function sellerIndex(int $unitId): array
    {
        $sellers = [];

        foreach (Vendedor::query()
            ->where('unidade_id', $unitId)
            ->orderByDesc('ativo')
            ->orderBy('id')
            ->get() as $seller) {
            $key = $this->normalizeIdentity($seller->nome);

            if ($key !== '' && ! isset($sellers[$key])) {
                $sellers[$key] = $seller;
            }
        }

        return $sellers;
    }

    /**
     * @param  array<string, Vendedor>  $sellers
     */
    private function resolveSeller(
        string $name,
        int $unitId,
        array &$sellers,
        int &$createdCount,
    ): Vendedor {
        $key = $this->normalizeIdentity($name);

        if (isset($sellers[$key])) {
            return $sellers[$key];
        }

        $seller = Vendedor::query()->create([
            'unidade_id' => $unitId,
            'nome' => $name,
            'ativo' => true,
        ]);
        $sellers[$key] = $seller;
        $createdCount++;

        return $seller;
    }

    private function visitStatus(string $leadStatus): string
    {
        return match ($this->normalizeLabel($leadStatus)) {
            $this->normalizeLabel('Atendendo') => 'Em Atendimento',
            $this->normalizeLabel('Sem Interesse') => 'Sem Interesse',
            default => 'Aguardando',
        };
    }

    private function parseDate(mixed $value, string $label): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return $this->validateDatabaseDate(CarbonImmutable::instance($value), $label);
        }

        if (is_numeric($value)) {
            if ((float) $value <= 0) {
                throw new InvalidArgumentException("{$label} não contém uma data válida.");
            }

            return $this->validateDatabaseDate(
                CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value)),
                $label,
            );
        }

        $text = trim((string) $value);

        if ($text === '') {
            throw new InvalidArgumentException("{$label} está vazia.");
        }

        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $text);
        $dateErrors = \DateTimeImmutable::getLastErrors();

        if (
            $date !== false
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        ) {
            return $this->validateDatabaseDate(CarbonImmutable::instance($date), $label);
        }

        try {
            $date = CarbonImmutable::parse($text);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("{$label} \"{$text}\" não contém uma data válida.");
        }

        return $this->validateDatabaseDate($date, $label);
    }

    private function validateDatabaseDate(CarbonImmutable $date, string $label): CarbonImmutable
    {
        if ($date->year < 1000 || $date->year > 9999) {
            throw new InvalidArgumentException(
                "{$label} \"{$date->format('d/m/Y')}\" está fora do intervalo de datas aceito pelo MySQL.",
            );
        }

        return $date;
    }

    private function optionalTime(mixed $value, string $label): ?string
    {
        if ($this->isBlank($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('H:i:s');
        }

        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 1) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))
                ->format('H:i:s');
        }

        $text = mb_strtolower(Str::ascii(trim((string) $value)));
        $text = preg_replace('/^as\s+/u', '', $text) ?? $text;

        if (in_array($text, ['manha', 'tarde', 'noite'], true)) {
            return null;
        }

        if (! preg_match('/^(\d{1,2})(?:\s*(?:h|:)\s*(\d{1,2})?)?$/u', $text, $matches)) {
            throw new InvalidArgumentException("{$label} \"{$text}\" não contém um horário válido.");
        }

        $hour = (int) $matches[1];
        $minute = (int) ($matches[2] ?? 0);

        if ($hour > 23 || $minute > 59) {
            throw new InvalidArgumentException("{$label} \"{$text}\" está fora do intervalo permitido.");
        }

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    private function parseDiscount(string $value): int
    {
        $hasPercentSign = str_contains($value, '%');
        $text = str_replace(',', '.', trim(str_replace('%', '', $value)));

        if (! is_numeric($text)) {
            throw new InvalidArgumentException("desconto \"{$value}\" não é válido.");
        }

        $discount = (float) $text;

        if (! $hasPercentSign && $discount >= 0 && $discount <= 1) {
            $discount *= 100;
        }

        if ($discount < 0 || $discount > 100) {
            throw new InvalidArgumentException("desconto \"{$value}\" está fora do intervalo de 0% a 100%.");
        }

        return (int) round($discount);
    }

    /**
     * @param  array{value: mixed, display: string}  $cell
     */
    private function cellString(array $cell): string
    {
        if ($this->isBlank($cell['value'])) {
            return '';
        }

        if ($cell['display'] !== '') {
            return trim($cell['display']);
        }

        return trim((string) $cell['value']);
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && in_array(trim($value), ['', '-'], true));
    }

    private function normalizePhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/u', '', $value);

        return is_string($digits) && strlen($digits) > 0 ? $digits : null;
    }

    private function normalizeIdentity(string $value): string
    {
        return $this->normalizeLabel($value);
    }

    private function normalizeLabel(string $value): string
    {
        $normalized = mb_strtolower(Str::ascii(trim($value)));
        $normalized = preg_replace('/(?<=\d)o(?=\s|$)/u', '', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/u', '', $normalized);
        $normalized = preg_replace('/^0+([1-9]\d*)/', '$1', $normalized);

        return $normalized ?? '';
    }
}
