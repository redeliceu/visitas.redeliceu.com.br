<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unidade_id' => ['required', 'integer', Rule::exists('unidades', 'id')->where('ativo', true)],
            'vendedor_id' => [
                'nullable',
                'integer',
                Rule::exists('vendedores', 'id')->where(fn ($query) => $query
                    ->where('ativo', true)
                    ->where('unidade_id', $request->input('unidade_id'))),
            ],
            'data' => ['nullable', 'date'],
            'responsavel' => ['required', 'string', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:40'],
            'telefone_secundario' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'aluno' => ['nullable', 'string', 'max:255'],
            'idade' => ['nullable', 'integer', 'min:0', 'max:120'],
            'nascimento' => ['nullable', 'date'],
            'serie_id' => ['nullable', 'integer', Rule::exists('series', 'id')->where('ativo', true)],
            'ciclo_id' => ['nullable', 'integer', Rule::exists('ciclos', 'id')->where('ativo', true)],
            'turno_id' => ['nullable', 'integer', Rule::exists('turnos', 'id')->where('ativo', true)],
            'canal_contato' => ['nullable', 'string', 'max:255'],
            'canal_mkt' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string', 'max:10000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
        ]);

        $status = LeadStatus::query()
            ->where('nome', 'Novo')
            ->where('ativo', true)
            ->first();

        if (! $status) {
            return response()->json(['message' => 'O serviço não está configurado para receber leads.'], 503);
        }

        $lead = DB::transaction(fn () => Lead::query()->create([
            ...$data,
            'data' => $data['data'] ?? now(),
            'status_id' => $status->id,
            'origem' => 'API',
        ]));

        return response()->json([
            'message' => 'Lead criado com sucesso.',
            'data' => [
                'id' => $lead->id,
                'unidade_id' => $lead->unidade_id,
                'status_id' => $lead->status_id,
                'origem' => $lead->origem,
                'created_at' => $lead->created_at,
            ],
        ], 201);
    }
}
