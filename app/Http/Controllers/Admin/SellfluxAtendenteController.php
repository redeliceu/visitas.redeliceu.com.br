<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellfluxAtendente;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Support\SellFlux\SellFluxAttendants;
use App\Support\SellFlux\SellFluxClient;
use App\Support\SellFlux\SellFluxRequestFailed;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SellfluxAtendenteController extends Controller
{
    private const ARCHIVED_UNIT = 'Esta unidade está arquivada e não recebe alterações.';

    public function __construct(private readonly SellFluxAttendants $attendants) {}

    public function index(Unidade $unidade): Response
    {
        $sellers = $this->attendants->sellers($unidade);

        return Inertia::render('admin/sellflux-atendentes', [
            'unit' => [
                'id' => $unidade->id,
                'nome' => $unidade->nome,
                'ativo' => $unidade->ativo,
                'sellflux_configurado' => $unidade->hasSellfluxToken(),
            ],
            'semVendedorAtivo' => $sellers->where('ativo', true)->isEmpty(),
            'sellers' => $sellers->map(fn (Vendedor $seller): array => [
                'id' => $seller->id,
                'nome' => $seller->nome,
                'ativo' => $seller->ativo,
                'tem_conta' => $seller->user_id !== null,
                'bloqueio_reativacao' => $seller->ativo ? null : $this->attendants->reactivationBlock($seller, $sellers),
            ])->values(),
            'atendentes' => $unidade->sellfluxAtendentes()
                ->with('vendedor:id,nome,ativo')
                ->orderByRaw('case situacao when ? then 0 when ? then 1 else 2 end', [
                    SellfluxAtendente::PENDENTE,
                    SellfluxAtendente::ASSOCIADO,
                ])
                ->orderBy('nome')
                ->get()
                ->map(fn (SellfluxAtendente $attendant): array => [
                    'id' => $attendant->id,
                    'nome' => $attendant->nome,
                    'situacao' => $attendant->situacao,
                    'vendedor_id' => $attendant->vendedor_id,
                    'vendedor_nome' => $attendant->vendedor?->nome,
                    'vendedor_ativo' => $attendant->vendedor?->ativo,
                    'visto_em' => $attendant->visto_em->format('d/m/Y H:i'),
                    'sugestao' => $attendant->situacao === SellfluxAtendente::PENDENTE
                        ? $this->attendants->suggestion($attendant, $sellers)
                        : null,
                ]),
        ]);
    }

    public function sync(Unidade $unidade): RedirectResponse
    {
        if (! $unidade->ativo) {
            return back()->with('warning', self::ARCHIVED_UNIT);
        }

        try {
            $token = $unidade->sellfluxToken();
        } catch (DecryptException) {
            return back()->with('warning', 'Não foi possível ler o token salvo desta unidade. Cadastre-o de novo.');
        }

        if ($token === null) {
            return back()->with('warning', 'Esta unidade não tem token da SellFlux.');
        }

        try {
            $new = $this->attendants->discover($unidade, new SellFluxClient($token));
        } catch (SellFluxRequestFailed $exception) {
            return back()->with('warning', $exception->describeFor('os atendimentos'));
        }

        return back()->with('success', match ($new) {
            0 => 'Lista atualizada. Nenhum atendente novo.',
            1 => 'Lista atualizada. 1 atendente novo.',
            default => "Lista atualizada. {$new} atendentes novos.",
        });
    }

    public function update(Request $request, Unidade $unidade, SellfluxAtendente $atendente): RedirectResponse
    {
        abort_unless((int) $atendente->unidade_id === (int) $unidade->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $unidade->ativo) {
            return back()->with('warning', self::ARCHIVED_UNIT);
        }

        $data = $request->validate([
            'acao' => ['required', Rule::in(['associar', 'criar', 'ignorar', 'desfazer'])],
            'vendedor_id' => ['required_if:acao,associar', 'nullable', 'integer'],
            'nome' => ['required_if:acao,criar', 'nullable', 'string', 'max:255'],
        ]);

        $message = match ($data['acao']) {
            'associar' => $this->associate($atendente, (int) $data['vendedor_id'], $user),
            'criar' => $this->createSeller($atendente, (string) $data['nome'], $user),
            'ignorar' => $this->changeState($atendente, SellfluxAtendente::IGNORADO, $user, 'Atendente ignorado.'),
            default => $this->changeState($atendente, SellfluxAtendente::PENDENTE, $user, 'Atendente voltou a ficar pendente.'),
        };

        return back()->with('success', $message);
    }

    private function associate(SellfluxAtendente $attendant, int $sellerId, User $user): string
    {
        return $this->attendants->associate($attendant, $sellerId, $user)
            ? 'Vendedor reativado e associado ao atendente.'
            : 'Vendedor associado ao atendente.';
    }

    private function createSeller(SellfluxAtendente $attendant, string $name, User $user): string
    {
        $seller = $this->attendants->associateWithNewSeller($attendant, $name, $user);

        return "Vendedor {$seller->nome} criado e associado ao atendente.";
    }

    private function changeState(SellfluxAtendente $attendant, string $state, User $user, string $message): string
    {
        $this->attendants->setState($attendant, $state, $user);

        return $message;
    }
}
