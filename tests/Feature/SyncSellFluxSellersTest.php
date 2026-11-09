<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadHistory;
use App\Models\LeadStatus;
use App\Models\SellfluxAtendente;
use App\Models\Unidade;
use App\Models\Vendedor;
use App\Support\SellFlux\SellFluxSellerSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncSellFluxSellersTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $unit;

    private Vendedor $seller;

    /** @var array<string, list<array<string, mixed>>> SellFlux leads by searched digits */
    private array $sellfluxLeads = [];

    /** @var array<string, list<array<string, mixed>>> chats by SellFlux lead id */
    private array $chats = [];

    /** @var array<string, list<array<string, mixed>>> attendances by SellFlux lead id */
    private array $attendances = [];

    /** @var array<string, list<array<string, mixed>>> team messages by attendance id */
    private array $messages = [];

    /** @var array<string, int> HTTP status forced per token */
    private array $failingTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Cache::flush();
        $this->unit = $this->unitWithToken('Centro', 'token-centro');
        $this->seller = Vendedor::query()->create(['unidade_id' => $this->unit->id, 'nome' => 'Ana Souza', 'ativo' => true]);
        $this->associate($this->unit, 'u-ana', $this->seller);

        Http::fake(function (Request $request) {
            $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');

            if (isset($this->failingTokens[$token])) {
                return Http::response(['msg' => 'Unauthorized'], $this->failingTokens[$token]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (preg_match('#^/api/v1/contact/leads/([^/]+)/links$#', $path, $match) === 1) {
                return Http::response(['links' => ['attendance' => $this->attendances[$match[1]] ?? []]]);
            }

            if (preg_match('#^/api/v1/contact/attendances/([^/]+)/messages$#', $path, $match) === 1) {
                return Http::response(['data' => $this->messages[$match[1]] ?? []]);
            }

            return match ($path) {
                '/api/v1/contact/leads' => Http::response(['data' => $this->sellfluxLeads[$query['search'] ?? ''] ?? []]),
                '/api/v1/contact/chats' => Http::response(['data' => $this->chats[$query['lead_id'] ?? ''] ?? []]),
                default => Http::response(['msg' => 'not faked'], 404),
            };
        });
    }

    private function unitWithToken(string $name, string $token): Unidade
    {
        $unit = Unidade::query()->create(['nome' => $name]);
        $unit->forceFill(['sellflux_token' => $token])->save();

        return $unit;
    }

    private function associate(Unidade $unit, string $sellfluxUserId, Vendedor $seller): SellfluxAtendente
    {
        return SellfluxAtendente::query()->create([
            'unidade_id' => $unit->id,
            'sellflux_user_id' => $sellfluxUserId,
            'nome' => $seller->nome,
            'vendedor_id' => $seller->id,
            'situacao' => SellfluxAtendente::ASSOCIADO,
            'visto_em' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(string $phone = '(11) 99999-0001', array $attributes = []): Lead
    {
        return Lead::query()->create([
            'unidade_id' => $this->unit->id,
            'responsavel' => 'Família Teste',
            'telefone' => $phone,
            ...$attributes,
        ]);
    }

    private function onSellFlux(string $national, string $sellfluxLeadId): void
    {
        $this->sellfluxLeads[$national][] = ['id' => $sellfluxLeadId, 'phone' => '+55'.$national];
    }

    private function chat(string $sellfluxLeadId, string $userId, string $name, string $createdAt): void
    {
        $this->chats[$sellfluxLeadId][] = [
            'lead_id' => $sellfluxLeadId,
            'attendant_kind' => 'user',
            'status' => 2,
            'user_attending' => ['id' => $userId, 'name' => $name],
            'created_at' => $createdAt,
        ];
    }

    /**
     * A finished attendance and the team members who wrote in it, oldest first.
     *
     * @param  list<string>  $writers
     */
    private function finishedAttendance(string $sellfluxLeadId, int $attendanceId, string $updatedAt, array $writers): void
    {
        $this->attendances[$sellfluxLeadId][] = [
            'id' => $attendanceId,
            'chat_id' => "chat-{$attendanceId}",
            'status' => 3,
            'attendant_kind' => 'finished',
            'updated_at' => $updatedAt,
        ];

        foreach ($writers as $minute => $userId) {
            $this->messages[(string) $attendanceId][] = [
                'from' => 'team',
                'user_id' => $userId,
                'created_at' => sprintf('%s 10:%02d:00', substr($updatedAt, 0, 10), $minute),
            ];
        }
    }

    private function ignore(string $sellfluxUserId): void
    {
        SellfluxAtendente::query()->create([
            'unidade_id' => $this->unit->id,
            'sellflux_user_id' => $sellfluxUserId,
            'nome' => 'Financeiro',
            'situacao' => SellfluxAtendente::IGNORADO,
            'visto_em' => now(),
        ]);
    }

    public function test_finished_attendance_links_the_last_team_member_who_wrote(): void
    {
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        // Current attendance is back in the queue, with no attendant.
        $this->finishedAttendance('sf-1', 101, '2026-10-01 12:00:00', ['u-outro']);
        $this->finishedAttendance('sf-1', 102, '2026-11-01 12:00:00', ['u-outro', 'u-ana']);

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertSame($this->seller->id, $lead->fresh()->vendedor_id);
        $history = LeadHistory::query()->where('lead_id', $lead->id)->sole();
        $this->assertContains(SellFluxSellerSync::REASON_PREVIOUS, array_column($history->changes, 'new'));
    }

    public function test_ignored_attendant_is_skipped_for_the_seller_before_them(): void
    {
        $this->ignore('u-financeiro');
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->chat('sf-1', 'u-financeiro', 'Financeiro', '2026-11-05T10:00:00Z');
        $this->finishedAttendance('sf-1', 201, '2026-11-05 12:00:00', ['u-ana', 'u-financeiro']);

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertSame($this->seller->id, $lead->fresh()->vendedor_id);
    }

    public function test_pending_attendant_stops_the_search_in_previous_attendances(): void
    {
        SellfluxAtendente::query()->create([
            'unidade_id' => $this->unit->id,
            'sellflux_user_id' => 'u-pendente',
            'nome' => 'Vendedor Novo',
            'visto_em' => now(),
        ]);
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->finishedAttendance('sf-1', 301, '2026-11-01 12:00:00', ['u-ana', 'u-pendente']);

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertNull($lead->fresh()->vendedor_id);
    }

    public function test_previous_attendances_are_read_only_when_the_current_way_fails(): void
    {
        $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->chat('sf-1', 'u-ana', 'Ana Souza', '2026-11-01T10:00:00Z');

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/links')
            || str_contains($request->url(), '/attendances/'));
    }

    public function test_only_the_three_most_recent_attendances_are_read(): void
    {
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->finishedAttendance('sf-1', 401, '2026-01-01 12:00:00', ['u-ana']);
        $this->finishedAttendance('sf-1', 402, '2026-09-01 12:00:00', ['u-outro']);
        $this->finishedAttendance('sf-1', 403, '2026-10-01 12:00:00', ['u-outro']);
        $this->finishedAttendance('sf-1', 404, '2026-11-01 12:00:00', ['u-outro']);

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertNull($lead->fresh()->vendedor_id);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/attendances/401/'));
    }

    public function test_links_the_seller_of_the_most_recent_chat_without_an_author(): void
    {
        $other = Vendedor::query()->create(['unidade_id' => $this->unit->id, 'nome' => 'Bruno Lima', 'ativo' => true]);
        $this->associate($this->unit, 'u-bruno', $other);
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->chat('sf-1', 'u-bruno', 'Bruno Lima', '2026-01-10T10:00:00Z');
        $this->chat('sf-1', 'u-ana', 'Ana Souza', '2026-11-01T10:00:00Z');

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertSame($this->seller->id, $lead->fresh()->vendedor_id);
        $history = LeadHistory::query()->where('lead_id', $lead->id)->sole();
        $this->assertNull($history->user_id);
        $this->assertContains(SellFluxSellerSync::REASON, array_column($history->changes, 'new'));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->chat('sf-1', 'u-novo', 'Pessoa Nova', '2026-11-01T10:00:00Z');
        $this->chat('sf-1', 'u-ana', 'Ana Souza', '2026-11-02T10:00:00Z');

        $this->artisan('sellflux:sync-sellers', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($lead->fresh()->vendedor_id);
        $this->assertSame(0, LeadHistory::query()->count());
        $this->assertFalse(SellfluxAtendente::query()->where('sellflux_user_id', 'u-novo')->exists());
        $this->assertFalse(Cache::has("sellflux-sync:cursor:{$this->unit->id}"));
    }

    public function test_skips_excluded_situations_and_leads_with_a_seller(): void
    {
        $lost = LeadStatus::query()->create(['nome' => 'Perdido']);
        $this->lead('(11) 99999-0002', ['status_id' => $lost->id]);
        $this->lead('(11) 99999-0003', ['vendedor_id' => $this->seller->id]);
        $open = $this->lead('(11) 99999-0004', ['status_id' => LeadStatus::query()->create(['nome' => 'Follow-up'])->id]);
        $this->onSellFlux('11999990004', 'sf-4');
        $this->chat('sf-4', 'u-ana', 'Ana Souza', '2026-11-01T10:00:00Z');

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertSame($this->seller->id, $open->fresh()->vendedor_id);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '999990002')
            || str_contains($request->url(), '999990003'));
    }

    public function test_attendant_without_a_seller_is_recorded_as_pending_and_the_lead_waits(): void
    {
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->chat('sf-1', 'u-novo', 'Pessoa Nova', '2026-11-01T10:00:00Z');

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertNull($lead->fresh()->vendedor_id);
        $this->assertSame(
            SellfluxAtendente::PENDENTE,
            SellfluxAtendente::query()->where('sellflux_user_id', 'u-novo')->value('situacao'),
        );
    }

    public function test_inactive_seller_does_not_receive_leads(): void
    {
        $this->seller->forceFill(['ativo' => false])->save();
        $lead = $this->lead();

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertNull($lead->fresh()->vendedor_id);
        Http::assertNothingSent();
    }

    public function test_two_sellflux_leads_with_the_same_phone_are_skipped(): void
    {
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->onSellFlux('11999990001', 'sf-2');
        $this->chat('sf-1', 'u-ana', 'Ana Souza', '2026-11-01T10:00:00Z');

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertNull($lead->fresh()->vendedor_id);
    }

    public function test_tries_the_secondary_phone_and_the_other_ninth_digit(): void
    {
        $lead = $this->lead('', ['telefone' => null, 'telefone_secundario' => '11 99999-0005']);
        $this->onSellFlux('1199990005', 'sf-5');
        $this->chat('sf-5', 'u-ana', 'Ana Souza', '2026-11-01T10:00:00Z');

        $this->artisan('sellflux:sync-sellers')->assertSuccessful();

        $this->assertSame($this->seller->id, $lead->fresh()->vendedor_id);
    }

    public function test_a_refused_token_stops_only_its_unit(): void
    {
        $broken = $this->unitWithToken('Norte', 'token-norte');
        $brokenSeller = Vendedor::query()->create(['unidade_id' => $broken->id, 'nome' => 'Caio', 'ativo' => true]);
        $this->associate($broken, 'u-caio', $brokenSeller);
        Lead::query()->create(['unidade_id' => $broken->id, 'responsavel' => 'Outra', 'telefone' => '11999990009']);
        $this->failingTokens['token-norte'] = 401;
        $lead = $this->lead();
        $this->onSellFlux('11999990001', 'sf-1');
        $this->chat('sf-1', 'u-ana', 'Ana Souza', '2026-11-01T10:00:00Z');

        $this->artisan('sellflux:sync-sellers')->assertFailed();

        $this->assertSame($this->seller->id, $lead->fresh()->vendedor_id);
    }

    public function test_the_limit_rotates_through_the_queue(): void
    {
        $first = $this->lead('(11) 99999-0001');
        $second = $this->lead('(11) 99999-0002');

        $this->artisan('sellflux:sync-sellers', ['--limite' => 1])->assertSuccessful();
        // Not found: the number and the version without the ninth digit.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '11999990001'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '999990002'));

        $this->artisan('sellflux:sync-sellers', ['--limite' => 1])->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '11999990002'));

        $this->assertNull($first->fresh()->vendedor_id);
        $this->assertNull($second->fresh()->vendedor_id);
    }

    public function test_the_schedule_runs_only_when_enabled(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'sellflux:sync-sellers'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);

        config(['services.sellflux.sync_enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['services.sellflux.sync_enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
