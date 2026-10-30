<?php

namespace App\Http\Controllers;

use App\Models\Campanha;
use App\Models\MetaVendedor;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Support\CommercialReportService;
use App\Support\UnitAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampanhaController extends Controller
{
    public function __construct(private readonly UnitAccess $unitAccess) {}

    public function index(Request $request, CommercialReportService $reportService): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $units = $this->unitAccess->availableUnits($user);
        $unitId = $this->unitAccess->selectedUnitId($request, $user, $units);
        $campaigns = $this->unitAccess->scope(Campanha::query(), $user)
            ->whereIn('unidade_id', $units->modelKeys())
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->with(['metasVendedores.vendedor:id,nome,unidade_id'])
            ->get()
            ->keyBy('unidade_id');
        $today = now()->toDateString();
        $visibleUnits = $unitId ? $units->where('id', $unitId)->values() : $units;
        $unitData = $visibleUnits->map(function (Unidade $unit) use ($campaigns, $reportService, $user, $today): array {
            /** @var Campanha|null $campaign */
            $campaign = $campaigns->get($unit->id);
            $summary = ['leads' => 0, 'appointments' => 0, 'visits' => 0, 'sales' => 0];
            $sellerResults = collect();

            if ($campaign?->inicio && $campaign->fim) {
                $end = min($campaign->fim->toDateString(), $today);
                if ($campaign->inicio->toDateString() <= $end) {
                    $campaignReport = $reportService->build(
                        $user,
                        $unit->id,
                        $campaign->inicio->toDateString(),
                        $end,
                    );
                    $summary = $campaignReport['all'];
                    $sellerResults = collect($campaignReport['bySeller'])->keyBy('seller_id');
                }
            }

            $sellers = Vendedor::query()
                ->where('unidade_id', $unit->id)
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn (Vendedor $seller): array => [
                    'id' => $seller->id,
                    'nome' => $seller->nome,
                    'meta' => $campaign?->metasVendedores
                        ->firstWhere('vendedor_id', $seller->id)?->meta,
                    'sales' => $sellerResults->get($seller->id)['sales'] ?? 0,
                ]);

            return [
                'id' => $unit->id,
                'nome' => $unit->nome,
                'campaign' => $campaign ? [
                    'id' => $campaign->id,
                    'unidade_id' => $campaign->unidade_id,
                    'inicio' => $campaign->inicio?->toDateString(),
                    'fim' => $campaign->fim?->toDateString(),
                    'meta' => $campaign->meta,
                ] : null,
                'sellers' => $sellers,
                'summary' => $summary,
            ];
        });

        return Inertia::render('campanhas/index', [
            'units' => $unitData,
            'availableUnits' => $units->map(fn (Unidade $unit) => [
                'id' => $unit->id,
                'nome' => $unit->nome,
            ]),
            'selectedUnitId' => $unitId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $this->validatedCampaign($request);
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);

        DB::transaction(function () use ($data): void {
            Unidade::query()->lockForUpdate()->findOrFail($data['unidade_id']);

            if (Campanha::query()->where('unidade_id', $data['unidade_id'])->exists()) {
                throw ValidationException::withMessages([
                    'unidade_id' => 'Esta unidade já possui uma campanha. Edite a campanha existente.',
                ]);
            }

            Campanha::query()->create($data + ['atualizado_em' => now()]);
        });

        return back()->with('success', 'Campanha cadastrada.');
    }

    public function update(Request $request, Campanha $campanha): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $campanha->unidade_id);
        $data = $this->validatedCampaign($request, $campanha->unidade_id);
        $campanha->update($data + ['atualizado_em' => now()]);

        return back()->with('success', 'Campanha atualizada.');
    }

    public function storeSellerGoal(Request $request, Campanha $campanha): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $campanha->unidade_id);
        $data = $request->validate([
            'vendedor_id' => [
                'required',
                'integer',
                Rule::exists('vendedores', 'id')->where(fn ($query) => $query
                    ->where('unidade_id', $campanha->unidade_id)
                    ->where('ativo', true)),
            ],
            'meta' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        DB::transaction(function () use ($campanha, $data): void {
            MetaVendedor::query()->updateOrCreate(
                [
                    'campanha_id' => $campanha->id,
                    'vendedor_id' => $data['vendedor_id'],
                ],
                ['meta' => $data['meta']],
            );
        });

        return back()->with('success', 'Meta individual atualizada.');
    }

    /**
     * @return array{unidade_id: int, inicio: string, fim: string, meta: int}
     */
    private function validatedCampaign(Request $request, ?int $unitId = null): array
    {
        $rules = [
            'unidade_id' => [
                'required',
                'integer',
                Rule::exists('unidades', 'id')->where('ativo', true),
                ...($unitId ? [Rule::in([$unitId])] : []),
            ],
            'inicio' => ['required', 'date'],
            'fim' => ['required', 'date', 'after_or_equal:inicio'],
            'meta' => ['required', 'integer', 'min:1', 'max:100000'],
        ];

        return $request->validate($rules);
    }
}
