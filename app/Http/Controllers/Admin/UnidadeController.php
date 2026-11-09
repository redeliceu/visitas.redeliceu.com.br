<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellfluxAtendente;
use App\Models\Unidade;
use App\Models\User;
use App\Support\SellFlux\SellFluxClient;
use App\Support\SellFlux\SellFluxRequestFailed;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UnidadeController extends Controller
{
    private const TOKEN_NOT_CONFIRMED = 'O token foi salvo, mas a SellFlux não respondeu para confirmá-lo. Tente salvar de novo mais tarde.';

    private const CONNECTION_CHECKS = [
        'os leads' => '/api/v1/contact/leads',
        'os atendimentos' => '/api/v1/contact/chats',
    ];

    public function index(): Response
    {
        return Inertia::render('admin/units', [
            'units' => Unidade::query()
                ->orderBy('nome')
                ->withCount(['sellfluxAtendentes as atendentes_pendentes' => fn ($query) => $query
                    ->where('situacao', SellfluxAtendente::PENDENTE)])
                ->get(['id', 'nome', 'ativo', 'sellflux_token', 'sellflux_project_id', 'sellflux_token_updated_at'])
                ->map(fn (Unidade $unit): array => [
                    'id' => $unit->id,
                    'nome' => $unit->nome,
                    'ativo' => $unit->ativo,
                    'sellflux_configurado' => $unit->hasSellfluxToken(),
                    'sellflux_projeto' => $unit->sellflux_project_id,
                    'sellflux_atualizado_em' => $unit->sellflux_token_updated_at?->format('d/m/Y H:i'),
                    'atendentes_pendentes' => (int) $unit->getAttribute('atendentes_pendentes'),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255', 'unique:unidades,nome'],
            ...$this->tokenRules(),
        ]);

        $unit = new Unidade(['nome' => $data['nome']]);
        $confirmed = $this->applyToken($unit, $data, $this->authenticatedUser($request));
        $unit->save();

        return $this->saved('Unidade criada.', $confirmed);
    }

    public function update(Request $request, Unidade $unidade): RedirectResponse
    {
        $data = $request->validate([
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('unidades', 'nome')->ignore($unidade->id),
            ],
            ...$this->tokenRules(),
        ]);

        $unidade->fill(['nome' => $data['nome']]);
        $confirmed = $this->applyToken($unidade, $data, $this->authenticatedUser($request));
        $unidade->save();

        return $this->saved('Unidade atualizada.', $confirmed);
    }

    public function setActive(Request $request, Unidade $unidade): RedirectResponse
    {
        $data = $request->validate([
            'ativo' => ['required', 'boolean'],
        ]);

        $unidade->update(['ativo' => $data['ativo']]);

        return back()->with('success', 'Status da unidade atualizado.');
    }

    /**
     * Reads, with the saved token, what the seller sync needs from SellFlux.
     * Response bodies are discarded, so no lead data reaches the page.
     */
    public function testSellflux(Unidade $unidade): RedirectResponse
    {
        try {
            $token = $unidade->sellfluxToken();
        } catch (DecryptException) {
            return back()->with('warning', 'Não foi possível ler o token salvo desta unidade. Cadastre-o de novo.');
        }

        if ($token === null) {
            return back()->with('warning', 'Esta unidade não tem token da SellFlux.');
        }

        $client = new SellFluxClient($token);

        try {
            $projectId = $client->projectId();
        } catch (SellFluxRequestFailed $exception) {
            return back()->with('warning', $exception->describeFor('dados do projeto'));
        }

        foreach (self::CONNECTION_CHECKS as $label => $path) {
            try {
                $client->get($path, ['limit' => 1]);
            } catch (SellFluxRequestFailed $exception) {
                return back()->with('warning', $exception->describeFor($label));
            }
        }

        if ($projectId !== null) {
            $unidade->forceFill(['sellflux_project_id' => $projectId])->save();
        }

        return back()->with('success', 'Conexão com a SellFlux funcionando: token aceito, leads e atendimentos acessíveis'
            .($projectId !== null ? " (projeto {$projectId})." : '.'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function tokenRules(): array
    {
        return [
            'sellflux_token' => ['nullable', 'string', 'max:4096', 'not_regex:/\s/'],
            'remover_sellflux_token' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * An empty field keeps the current token; removing it takes an explicit
     * choice. A token SellFlux refuses is never saved. If SellFlux can't be
     * reached the token is kept, since that says nothing about the token.
     *
     * @param  array<string, mixed>  $data
     * @return bool false when the token was saved but could not be confirmed
     */
    private function applyToken(Unidade $unit, array $data, User $user): bool
    {
        $token = $data['sellflux_token'] ?? null;
        $confirmed = true;

        if (is_string($token)) {
            $projectId = null;

            try {
                $projectId = (new SellFluxClient($token))->projectId();
            } catch (SellFluxRequestFailed $exception) {
                if ($exception->isAuthenticationFailure()) {
                    throw ValidationException::withMessages([
                        'sellflux_token' => 'A SellFlux recusou este token. Confira se ele foi copiado por inteiro.',
                    ]);
                }

                $confirmed = false;
            }

            $unit->forceFill([
                'sellflux_token' => $token,
                'sellflux_project_id' => $projectId,
            ]);
        } elseif (($data['remover_sellflux_token'] ?? false) && $unit->hasSellfluxToken()) {
            $unit->forceFill([
                'sellflux_token' => null,
                'sellflux_project_id' => null,
            ]);
        } else {
            return true;
        }

        $unit->forceFill([
            'sellflux_token_updated_at' => now(),
            'sellflux_token_updated_by' => $user->id,
        ]);

        return $confirmed;
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function saved(string $message, bool $confirmed): RedirectResponse
    {
        $redirect = back()->with('success', $message);

        return $confirmed ? $redirect : $redirect->with('warning', self::TOKEN_NOT_CONFIRMED);
    }
}
