<?php

namespace Tests\Feature;

use App\Models\SellfluxAtendente;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Support\SellerLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellfluxAtendenteTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Unidade $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $this->unit = Unidade::query()->create(['nome' => 'Centro']);
        $this->unit->forceFill(['sellflux_token' => 'token-de-teste'])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function chat(int|string|null $userId, ?string $name): array
    {
        return [
            'attendant_kind' => 'user',
            'user_attending' => $userId === null ? null : ['id' => $userId, 'name' => $name],
            'from' => '+5511999990000',
        ];
    }

    private function attendant(string $name = 'Ana Souza', ?Unidade $unit = null): SellfluxAtendente
    {
        return SellfluxAtendente::query()->create([
            'unidade_id' => ($unit ?? $this->unit)->id,
            'sellflux_user_id' => (string) random_int(1000, 9999),
            'nome' => $name,
            'visto_em' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seller(string $name = 'Ana Souza', bool $active = true, array $attributes = []): Vendedor
    {
        return Vendedor::query()->create([
            'unidade_id' => $this->unit->id,
            'nome' => $name,
            'ativo' => $active,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function act(SellfluxAtendente $attendant, array $data): TestResponse
    {
        return $this->actingAs($this->master)->put(
            route('admin.units.atendentes.update', [$attendant->unidade_id, $attendant->id]),
            $data,
        );
    }

    public function test_sync_records_attendants_across_pages_without_touching_associations(): void
    {
        $firstPage = array_fill(0, 30, $this->chat(10, 'Ana Souza'));
        $firstPage[1] = $this->chat(20, 'Bruno Lima');
        $seller = $this->seller();
        SellfluxAtendente::query()->create([
            'unidade_id' => $this->unit->id,
            'sellflux_user_id' => '10',
            'nome' => 'Nome antigo',
            'vendedor_id' => $seller->id,
            'situacao' => SellfluxAtendente::ASSOCIADO,
            'visto_em' => now()->subDay(),
        ]);
        Http::fake(['*/api/v1/contact/chats*' => Http::sequence()
            ->push(['data' => $firstPage])
            ->push(['data' => [$this->chat(30, 'Carla Dias'), $this->chat(null, null)]])]);

        $this->actingAs($this->master)
            ->post(route('admin.units.atendentes.sync', $this->unit))
            ->assertSessionHas('success', 'Lista atualizada. 2 atendentes novos.');

        $this->assertSame(3, SellfluxAtendente::query()->count());
        $existing = SellfluxAtendente::query()->where('sellflux_user_id', '10')->firstOrFail();
        $this->assertSame('Ana Souza', $existing->nome);
        $this->assertSame(SellfluxAtendente::ASSOCIADO, $existing->situacao);
        $this->assertSame($seller->id, (int) $existing->vendedor_id);
        $new = SellfluxAtendente::query()->where('sellflux_user_id', '20')->firstOrFail();
        $this->assertSame(SellfluxAtendente::PENDENTE, $new->situacao);
        $this->assertNull($new->vendedor_id);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && ($request->data()['attendant'] ?? null) === 'user');
    }

    public function test_the_same_sellflux_id_is_separate_in_each_unit(): void
    {
        $other = Unidade::query()->create(['nome' => 'Norte']);
        $other->forceFill(['sellflux_token' => 'outro-token'])->save();
        Http::fake(['*/api/v1/contact/chats*' => Http::response(['data' => [$this->chat(10, 'Ana Souza')]])]);

        $this->actingAs($this->master)->post(route('admin.units.atendentes.sync', $this->unit));
        $this->actingAs($this->master)->post(route('admin.units.atendentes.sync', $other));

        $this->assertSame(1, $this->unit->sellfluxAtendentes()->count());
        $this->assertSame(1, $other->sellfluxAtendentes()->count());
    }

    public function test_sync_reports_sellflux_failures(): void
    {
        Http::fake(['*' => Http::response(['msg' => 'Chave não encontrada'], 500)]);

        $this->actingAs($this->master)
            ->post(route('admin.units.atendentes.sync', $this->unit))
            ->assertSessionHas('warning', 'O token não foi recusado, mas a SellFlux retornou um erro ao consultar os atendimentos (HTTP 500: Chave não encontrada).');
    }

    public function test_a_name_match_is_only_a_suggestion(): void
    {
        $seller = $this->seller('Ána  Souza');
        $attendant = $this->attendant('ana souza');

        $this->actingAs($this->master)->get(route('admin.units.atendentes.index', $this->unit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/sellflux-atendentes')
                ->where('semVendedorAtivo', false)
                ->where('atendentes.0.situacao', 'pendente')
                ->where('atendentes.0.sugestao', ['tipo' => 'vendedor', 'vendedor_id' => $seller->id, 'reativar' => false]));

        $this->assertNull($attendant->refresh()->vendedor_id);
    }

    public function test_no_suggestion_when_the_unit_has_sellers_but_no_name_matches(): void
    {
        $this->seller('Outra Pessoa');
        $this->attendant('Ana Souza');

        $this->actingAs($this->master)->get(route('admin.units.atendentes.index', $this->unit))
            ->assertInertia(fn (Assert $page) => $page->where('atendentes.0.sugestao', null));
    }

    public function test_repeated_seller_names_produce_no_suggestion(): void
    {
        $this->seller('Ana Souza');
        $this->seller('Ana Souza');
        $this->attendant('Ana Souza');

        $this->actingAs($this->master)->get(route('admin.units.atendentes.index', $this->unit))
            ->assertInertia(fn (Assert $page) => $page->where('atendentes.0.sugestao', null));
    }

    public function test_without_active_sellers_it_suggests_reactivating_an_inactive_one(): void
    {
        $inactive = $this->seller('Ana Souza', false);
        $this->attendant('Ana Souza');

        $this->actingAs($this->master)->get(route('admin.units.atendentes.index', $this->unit))
            ->assertInertia(fn (Assert $page) => $page->where('semVendedorAtivo', true)
                ->where('atendentes.0.sugestao', ['tipo' => 'vendedor', 'vendedor_id' => $inactive->id, 'reativar' => true]));
    }

    public function test_without_any_match_or_active_seller_it_suggests_creating_one(): void
    {
        $this->seller('Outra Pessoa', false);
        $this->attendant('Ana Souza');

        $this->actingAs($this->master)->get(route('admin.units.atendentes.index', $this->unit))
            ->assertInertia(fn (Assert $page) => $page->where('semVendedorAtivo', true)
                ->where('atendentes.0.sugestao', ['tipo' => 'criar']));
    }

    public function test_it_explains_a_blocked_seller_instead_of_suggesting_a_duplicate(): void
    {
        $account = User::factory()->create(['ativo' => false]);
        $this->seller('Ana Souza', false, ['user_id' => $account->id]);
        $this->attendant('Ana Souza');

        $this->actingAs($this->master)->get(route('admin.units.atendentes.index', $this->unit))
            ->assertInertia(fn (Assert $page) => $page
                ->where('atendentes.0.sugestao.tipo', 'bloqueado')
                ->where('atendentes.0.sugestao.motivo', 'a conta deste vendedor está inativa ou fora da unidade; ajuste em Usuários'));
    }

    public function test_master_associates_an_active_seller(): void
    {
        $seller = $this->seller();
        $attendant = $this->attendant();

        $this->act($attendant, ['acao' => 'associar', 'vendedor_id' => $seller->id])
            ->assertSessionHas('success', 'Vendedor associado ao atendente.');

        $attendant->refresh();
        $this->assertSame(SellfluxAtendente::ASSOCIADO, $attendant->situacao);
        $this->assertSame($seller->id, (int) $attendant->vendedor_id);
        $this->assertSame($this->master->id, (int) $attendant->alterado_por);
    }

    public function test_associating_a_reactivable_seller_reactivates_it(): void
    {
        $seller = $this->seller('Ana Souza', false);
        $attendant = $this->attendant();

        $this->act($attendant, ['acao' => 'associar', 'vendedor_id' => $seller->id])
            ->assertSessionHas('success', 'Vendedor reativado e associado ao atendente.');

        $this->assertTrue($seller->refresh()->ativo);
        $this->assertSame($seller->id, (int) $attendant->refresh()->vendedor_id);
    }

    public function test_seller_following_an_account_is_not_reactivated(): void
    {
        $account = User::factory()->create(['ativo' => false]);
        $seller = $this->seller('Ana Souza', false, ['user_id' => $account->id]);
        $attendant = $this->attendant();

        $this->act($attendant, ['acao' => 'associar', 'vendedor_id' => $seller->id])
            ->assertSessionHasErrors('vendedor_id');

        $this->assertFalse($seller->refresh()->ativo);
        $this->assertNull($attendant->refresh()->vendedor_id);
    }

    public function test_leftover_of_a_merge_is_not_reactivated(): void
    {
        $this->seller('Ana Souza');
        $leftover = $this->seller('ANA SOUZA', false);
        $attendant = $this->attendant();

        $this->act($attendant, ['acao' => 'associar', 'vendedor_id' => $leftover->id])
            ->assertSessionHasErrors('vendedor_id');

        $this->assertFalse($leftover->refresh()->ativo);
        $this->assertSame(SellfluxAtendente::PENDENTE, $attendant->refresh()->situacao);
    }

    public function test_seller_from_another_unit_is_refused(): void
    {
        $other = Unidade::query()->create(['nome' => 'Norte']);
        $seller = Vendedor::query()->create(['unidade_id' => $other->id, 'nome' => 'Ana Souza', 'ativo' => false]);
        $attendant = $this->attendant();

        $this->act($attendant, ['acao' => 'associar', 'vendedor_id' => $seller->id])
            ->assertSessionHasErrors('vendedor_id');

        $this->assertFalse($seller->refresh()->ativo);
        $this->assertNull($attendant->refresh()->vendedor_id);
    }

    public function test_master_creates_a_seller_and_associates_it(): void
    {
        $attendant = $this->attendant();

        $this->act($attendant, ['acao' => 'criar', 'nome' => '  Ana Souza  '])->assertSessionHasNoErrors();

        $seller = Vendedor::query()->where('nome', 'Ana Souza')->firstOrFail();
        $this->assertSame($this->unit->id, (int) $seller->unidade_id);
        $this->assertTrue($seller->ativo);
        $this->assertNull($seller->user_id);
        $this->assertSame($seller->id, (int) $attendant->refresh()->vendedor_id);
    }

    public function test_creating_a_seller_with_an_existing_name_is_refused(): void
    {
        $this->seller('ana souza', false);
        $attendant = $this->attendant();

        $this->act($attendant, ['acao' => 'criar', 'nome' => 'Ána Souza'])->assertSessionHasErrors('nome');

        $this->assertSame(1, Vendedor::query()->count());
        $this->assertNull($attendant->refresh()->vendedor_id);
    }

    public function test_attendant_can_be_ignored_and_undone(): void
    {
        $seller = $this->seller();
        $attendant = $this->attendant();
        $this->act($attendant, ['acao' => 'associar', 'vendedor_id' => $seller->id]);

        $this->act($attendant, ['acao' => 'ignorar'])->assertSessionHasNoErrors();
        $attendant->refresh();
        $this->assertSame(SellfluxAtendente::IGNORADO, $attendant->situacao);
        $this->assertNull($attendant->vendedor_id);

        $this->act($attendant, ['acao' => 'desfazer'])->assertSessionHasNoErrors();
        $this->assertSame(SellfluxAtendente::PENDENTE, $attendant->refresh()->situacao);
    }

    public function test_attendant_from_another_unit_is_not_found(): void
    {
        $other = Unidade::query()->create(['nome' => 'Norte']);
        $attendant = $this->attendant('Ana Souza', $other);

        $this->actingAs($this->master)
            ->put(route('admin.units.atendentes.update', [$this->unit->id, $attendant->id]), ['acao' => 'ignorar'])
            ->assertNotFound();

        $this->assertSame(SellfluxAtendente::PENDENTE, $attendant->refresh()->situacao);
    }

    public function test_archived_unit_receives_no_changes(): void
    {
        $attendant = $this->attendant();
        $this->unit->update(['ativo' => false]);
        Http::fake();

        $this->act($attendant, ['acao' => 'criar', 'nome' => 'Ana Souza'])->assertSessionHas('warning');
        $this->actingAs($this->master)->post(route('admin.units.atendentes.sync', $this->unit))->assertSessionHas('warning');

        $this->assertSame(0, Vendedor::query()->count());
        Http::assertNothingSent();
    }

    public function test_only_master_reaches_the_screen_and_actions(): void
    {
        Http::fake();
        $attendant = $this->attendant();
        $seller = User::factory()->create();

        $this->actingAs($seller)->get(route('admin.units.atendentes.index', $this->unit))->assertForbidden();
        $this->actingAs($seller)->post(route('admin.units.atendentes.sync', $this->unit))->assertForbidden();
        $this->actingAs($seller)
            ->put(route('admin.units.atendentes.update', [$this->unit->id, $attendant->id]), ['acao' => 'ignorar'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_screen_never_receives_the_token(): void
    {
        $this->actingAs($this->master)->get(route('admin.units.atendentes.index', $this->unit))
            ->assertOk()
            ->assertDontSee('token-de-teste', false);
    }

    public function test_units_page_counts_pending_attendants(): void
    {
        $this->attendant('Ana Souza');
        $ignored = $this->attendant('Bruno Lima');
        $ignored->forceFill(['situacao' => SellfluxAtendente::IGNORADO])->save();

        $this->actingAs($this->master)->get(route('admin.units.index'))
            ->assertInertia(fn (Assert $page) => $page->where('units.0.atendentes_pendentes', 1));
    }

    public function test_association_follows_a_seller_merge(): void
    {
        $account = User::factory()->create();
        $account->unidades()->attach($this->unit);
        $automatic = $this->seller('Conta Automática', true, ['user_id' => $account->id]);
        $imported = $this->seller('Vendedor Importado');
        $attendant = $this->attendant();
        $attendant->forceFill(['vendedor_id' => $automatic->id, 'situacao' => SellfluxAtendente::ASSOCIADO])->save();

        app(SellerLinkService::class)->link($account, $imported);

        $this->assertFalse($automatic->refresh()->ativo);
        $this->assertSame($imported->id, (int) $attendant->refresh()->vendedor_id);
    }
}
