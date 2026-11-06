import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    CreateFollowUpModal,
    FollowUpsModal,
    ScheduleVisitModal,
} from '@/components/visitas/FollowUpModals';
import type { FollowUpNote } from '@/components/visitas/FollowUpModals';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Appointment = {
    id: number;
    lead_id: number | null;
    data: string | null;
    horario: string | null;
    responsavel: string | null;
    telefone: string | null;
    aluno: string | null;
    unidade: string | null;
};
type TodayVisit = {
    id: number;
    horario: string | null;
    responsavel: string | null;
    telefone: string | null;
    aluno: string | null;
    status: string;
    unidade: string | null;
};
type QueueLead = {
    id: number;
    data: string | null;
    created_at: string;
    responsavel: string | null;
    aluno: string | null;
    telefone: string | null;
    canal: string | null;
    status: string | null;
    unidade: string | null;
};
type FollowUp = {
    id: number;
    responsavel: string | null;
    aluno: string | null;
    serie: string | null;
    telefone: string | null;
    status: string | null;
    unidade: string | null;
    retorno_data: string | null;
    overdue: boolean;
    overdue_days: number;
    idle_days: number;
    bucket: Exclude<WaitTab, 'todos'> | null;
    follow_ups_count: number;
    initial_note: { texto: string; data: string | null } | null;
    follow_ups: FollowUpNote[];
};
type Goal = {
    id: number;
    unidade: string | null;
    inicio: string;
    fim: string;
    active: boolean;
    goal: number | null;
    sales: number;
    expected_sales: number | null;
};
type Performance = {
    leads: number;
    appointments: number;
    visits: number;
    sales: number;
};
type Props = {
    auth: Auth;
    units: Unit[];
    selectedUnitId: number | null;
    agenda: { appointments: Appointment[]; visits: TodayVisit[] };
    queue: { total: number; leads: QueueLead[] };
    followUps: {
        total: number;
        overdue: number;
        idle: number;
        counts: Record<WaitTab, number>;
        leads: FollowUp[];
    };
    goals: Goal[];
    performance: Performance;
};
type AgendaItem = {
    key: string;
    time: string | null;
    name: string;
    detail: string;
    kind: 'visit' | 'appointment';
    tag: string;
    href: string;
    search: SearchFields;
};
type SearchFields = {
    names: Array<string | null>;
    phones: Array<string | null>;
};

const IDLE_DAYS = 7;
const QUEUE_PREVIEW = 5;
const FOLLOW_UP_PREVIEW = 6;

type WaitTab = 'todos' | 'vencidos' | 'hoje' | 'proximos' | 'parados';

const iconProps = {
    'aria-hidden': true,
    fill: 'none',
    height: 15,
    stroke: 'currentColor',
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    strokeWidth: 2,
    viewBox: '0 0 24 24',
    width: 15,
};

// Waiting-period tabs (D-030); the bucket of each lead comes from the server.
// Each tab has its own colour and icon (D-033).
const WAIT_TABS: Array<{ id: WaitTab; label: string; icon: ReactNode }> = [
    {
        id: 'todos',
        label: 'Todos',
        icon: (
            <svg {...iconProps}>
                <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
            </svg>
        ),
    },
    {
        id: 'vencidos',
        label: 'Vencidos',
        icon: (
            <svg {...iconProps}>
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v5M12 16h.01" />
            </svg>
        ),
    },
    {
        id: 'hoje',
        label: 'Retorno hoje',
        icon: (
            <svg {...iconProps}>
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v5l3 2" />
            </svg>
        ),
    },
    {
        id: 'proximos',
        label: 'Próximos 7 dias',
        icon: (
            <svg {...iconProps}>
                <rect height="17" rx="2" width="18" x="3" y="4" />
                <path d="M16 2v4M8 2v4M3 10h18" />
            </svg>
        ),
    },
    {
        id: 'parados',
        label: `Parados +${IDLE_DAYS} dias`,
        icon: (
            <svg {...iconProps}>
                <path d="M10 4H6v16h4zM18 4h-4v16h4z" />
            </svg>
        ),
    },
];

/** Opens on the most urgent tab that has leads (D-033). */
function initialTab(counts: Record<WaitTab, number>): WaitTab {
    if (counts.vencidos > 0) {
        return 'vencidos';
    }

    return counts.hoje > 0 ? 'hoje' : 'todos';
}

function normalizeText(value: string | null | undefined): string {
    return (value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

function onlyDigits(value: string | null | undefined): string {
    return (value ?? '').replace(/\D/g, '');
}

/** Name (responsável or aluno) or phone search used across the panel (D-033). */
function matchesSearch(query: string, fields: SearchFields): boolean {
    const text = normalizeText(query).trim();

    if (text === '') {
        return true;
    }

    if (fields.names.some((name) => normalizeText(name).includes(text))) {
        return true;
    }

    const queryDigits = onlyDigits(query);

    return (
        queryDigits.length >= 3 &&
        fields.phones.some((phone) => onlyDigits(phone).includes(queryDigits))
    );
}

function localDate(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [, month, day] = value.slice(0, 10).split('-');

    return `${day}/${month}`;
}

function plural(count: number, singular: string, pluralForm: string): string {
    return `${count} ${count === 1 ? singular : pluralForm}`;
}

function minutesUntil(time: string | null, now: Date): number | null {
    if (!time) {
        return null;
    }

    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes - (now.getHours() * 60 + now.getMinutes());
}

function relativeIn(minutes: number): string {
    if (minutes < 60) {
        return `em ${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest
        ? `em ${hours}h${String(rest).padStart(2, '0')}`
        : `em ${hours}h`;
}

function timeAgo(iso: string, now: Date): string {
    const minutes = Math.max(
        0,
        Math.round((now.getTime() - new Date(iso).getTime()) / 60000),
    );

    if (minutes < 60) {
        return `há ${minutes} min`;
    }

    if (minutes < 60 * 24) {
        return `há ${Math.round(minutes / 60)}h`;
    }

    return `há ${plural(Math.round(minutes / 1440), 'dia', 'dias')}`;
}

function greeting(now: Date): string {
    const hour = now.getHours();

    if (hour < 12) {
        return 'Bom dia';
    }

    return hour < 18 ? 'Boa tarde' : 'Boa noite';
}

function followUpBadge(lead: FollowUp): { label: string; tone: string } {
    if (lead.overdue) {
        return {
            label: `Vencido há ${plural(lead.overdue_days, 'dia', 'dias')}`,
            tone: 'danger',
        };
    }

    if (lead.bucket === 'parados') {
        return {
            label: `Parado há ${lead.idle_days} dias`,
            tone: 'warning',
        };
    }

    if (lead.bucket === 'hoje') {
        return { label: 'Retorno hoje', tone: 'info' };
    }

    if (lead.retorno_data) {
        return { label: `Retorno ${formatDate(lead.retorno_data)}`, tone: '' };
    }

    return {
        label:
            lead.idle_days === 0
                ? 'Movimentado hoje'
                : `Sem contato há ${plural(lead.idle_days, 'dia', 'dias')}`,
        tone: '',
    };
}

function percentage(value: number, total: number): string {
    return total > 0
        ? `${((value / total) * 100).toFixed(1).replace('.', ',')}%`
        : '0,0%';
}

export default function SellerPanel({
    auth,
    units,
    selectedUnitId,
    agenda,
    queue,
    followUps,
    goals,
    performance,
}: Props) {
    const [showAllFollowUps, setShowAllFollowUps] = useState(false);
    const [waitTab, setWaitTab] = useState<WaitTab>(() =>
        initialTab(followUps.counts),
    );
    const [search, setSearch] = useState('');
    const [creatingForId, setCreatingForId] = useState<number | null>(null);
    const [viewingId, setViewingId] = useState<number | null>(null);
    const [schedulingId, setSchedulingId] = useState<number | null>(null);
    // Looked up by id so the modals show fresh data after each save.
    const creatingFor =
        followUps.leads.find((lead) => lead.id === creatingForId) ?? null;
    const viewing =
        followUps.leads.find((lead) => lead.id === viewingId) ?? null;
    const scheduling =
        followUps.leads.find((lead) => lead.id === schedulingId) ?? null;
    const user = auth.user;

    if (!user) {
        return null;
    }

    const now = new Date();
    const today = localDate(now);
    const dateLabel = now.toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
    const firstName = user.name.split(' ')[0];

    const agendaToday: AgendaItem[] = [
        ...agenda.appointments
            .filter((appointment) => appointment.data === today)
            .map((appointment): AgendaItem => ({
                key: `a-${appointment.id}`,
                time: appointment.horario,
                name: appointment.responsavel ?? 'Responsável não informado',
                detail: ['Agendamento', appointment.aluno, appointment.unidade]
                    .filter(Boolean)
                    .join(' · '),
                kind: 'appointment',
                tag: 'Agendado',
                href: '/agendamentos',
                search: {
                    names: [appointment.responsavel, appointment.aluno],
                    phones: [appointment.telefone],
                },
            })),
        ...agenda.visits.map((visit): AgendaItem => ({
            key: `v-${visit.id}`,
            time: visit.horario,
            name: visit.responsavel ?? 'Responsável não informado',
            detail: ['Visita', visit.aluno, visit.unidade]
                .filter(Boolean)
                .join(' · '),
            kind: 'visit',
            tag: visit.status,
            href: '/visitas',
            search: {
                names: [visit.responsavel, visit.aluno],
                phones: [visit.telefone],
            },
        })),
    ].sort((a, b) => (a.time ?? '99').localeCompare(b.time ?? '99'));
    const upcomingDays = agenda.appointments.filter(
        (appointment) => appointment.data !== today,
    ).length;
    const next = agendaToday.find((item) => {
        const minutes = minutesUntil(item.time, now);

        return minutes !== null && minutes >= 0;
    });
    const nextMinutes = next ? minutesUntil(next.time, now) : null;
    const oldestOverdue = followUps.leads.find((lead) => lead.overdue);
    const newestQueue = queue.leads[0];
    const searching = search.trim() !== '';
    const agendaShown = agendaToday.filter((item) =>
        matchesSearch(search, item.search),
    );
    const queueShown = searching
        ? queue.leads.filter((lead) =>
              matchesSearch(search, {
                  names: [lead.responsavel, lead.aluno],
                  phones: [lead.telefone],
              }),
          )
        : queue.leads.slice(0, QUEUE_PREVIEW);
    const searchedFollowUps = followUps.leads.filter((lead) =>
        matchesSearch(search, {
            names: [lead.responsavel, lead.aluno],
            phones: [lead.telefone],
        }),
    );
    // While searching, tab counts reflect the matches among the loaded leads.
    const tabCounts: Record<WaitTab, number> = searching
        ? {
              todos: searchedFollowUps.length,
              vencidos: searchedFollowUps.filter((l) => l.bucket === 'vencidos')
                  .length,
              hoje: searchedFollowUps.filter((l) => l.bucket === 'hoje').length,
              proximos: searchedFollowUps.filter((l) => l.bucket === 'proximos')
                  .length,
              parados: searchedFollowUps.filter((l) => l.bucket === 'parados')
                  .length,
          }
        : followUps.counts;
    const tabFollowUps =
        waitTab === 'todos'
            ? searchedFollowUps
            : searchedFollowUps.filter((lead) => lead.bucket === waitTab);
    const visibleFollowUps =
        showAllFollowUps || searching
            ? tabFollowUps
            : tabFollowUps.slice(0, FOLLOW_UP_PREVIEW);
    const tabTotal = tabCounts[waitTab];
    const resultCount =
        agendaShown.length + queueShown.length + searchedFollowUps.length;
    const activeGoals = goals.filter((goal) => goal.active);

    const summary = [
        {
            label: 'Compromissos hoje',
            value: agendaToday.length,
            hint: next ? `Próximo às ${next.time}` : 'Nenhum pendente hoje',
            href: '#agenda',
            tone: 'primary',
        },
        {
            label: 'Retornos vencidos',
            value: followUps.overdue,
            hint: oldestOverdue
                ? `Mais antigo: ${plural(oldestOverdue.overdue_days, 'dia', 'dias')}`
                : 'Tudo em dia',
            href: '#follow-ups',
            tone: followUps.overdue > 0 ? 'danger' : 'neutral',
        },
        {
            label: 'Follow-ups em aberto',
            value: followUps.total,
            hint:
                followUps.idle > 0
                    ? `${plural(followUps.idle, 'parado', 'parados')} há mais de ${IDLE_DAYS} dias`
                    : 'Nenhum parado',
            href: '#follow-ups',
            tone: 'neutral',
        },
        {
            label: 'Fila da unidade',
            value: queue.total,
            hint: newestQueue
                ? `Mais recente ${timeAgo(newestQueue.created_at, now)}`
                : 'Fila vazia',
            href: '#fila',
            tone: 'info',
        },
    ];

    function claim(leadId: number) {
        router.post(`/leads/${leadId}/claim`, {}, { preserveScroll: true });
    }

    return (
        <VisitLayout
            active="home"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Meu painel"
            units={units}
        >
            <div className="sp-page">
                <header className="sp-header">
                    <div>
                        <div className="sp-eyebrow">{dateLabel}</div>
                        <h1>
                            {greeting(now)}, {firstName}
                        </h1>
                    </div>
                    <div className="sp-header-actions">
                        <a
                            className="sp-btn sp-btn-primary"
                            href="/leads?acao=novo"
                        >
                            + Novo lead
                        </a>
                        <a className="sp-btn sp-btn-secondary" href="/leads">
                            Meus leads
                        </a>
                    </div>
                </header>

                <section aria-label="Buscar no painel" className="sp-search">
                    <label className="sp-search-field" htmlFor="sp-search">
                        <svg {...iconProps} height={18} width={18}>
                            <circle cx="11" cy="11" r="7" />
                            <path d="m20 20-3.5-3.5" />
                        </svg>
                        <span className="sp-visually-hidden">
                            Buscar por nome ou telefone
                        </span>
                        <input
                            autoComplete="off"
                            id="sp-search"
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar por nome do responsável, do aluno ou telefone"
                            type="search"
                            value={search}
                        />
                        {searching && (
                            <button
                                aria-label="Limpar busca"
                                className="sp-search-clear"
                                onClick={() => setSearch('')}
                                type="button"
                            >
                                ×
                            </button>
                        )}
                    </label>
                    {searching && (
                        <p aria-live="polite" className="sp-search-result">
                            {resultCount === 0
                                ? 'Nada encontrado no painel.'
                                : `${plural(resultCount, 'resultado', 'resultados')}: ${agendaShown.length} na agenda, ${queueShown.length} na fila e ${searchedFollowUps.length} em follow-ups.`}
                        </p>
                    )}
                </section>

                <section aria-label="Resumo do dia" className="sp-summary">
                    {summary.map((item) => (
                        <a
                            className={`sp-summary-card sp-tone-${item.tone}`}
                            href={item.href}
                            key={item.label}
                        >
                            <span className="sp-summary-label">
                                {item.label}
                            </span>
                            <span className="sp-summary-value">
                                {item.value}
                            </span>
                            <span className="sp-summary-hint">{item.hint}</span>
                        </a>
                    ))}
                </section>

                <div className="sp-row">
                    <section
                        aria-labelledby="sp-agenda-title"
                        className="sp-card sp-agenda"
                        id="agenda"
                    >
                        <div className="sp-card-head">
                            <h2 id="sp-agenda-title">Agenda de hoje</h2>
                            <a href="/agendamentos">Ver semana</a>
                        </div>
                        {agendaShown.length ? (
                            <ol className="sp-agenda-list">
                                {agendaShown.map((item) => {
                                    const isNext = item.key === next?.key;

                                    return (
                                        <li
                                            className={`sp-agenda-item${isNext ? ' is-next' : ''}`}
                                            key={item.key}
                                        >
                                            <div className="sp-agenda-time">
                                                <strong>
                                                    {item.time ?? '—'}
                                                </strong>
                                                {isNext &&
                                                    nextMinutes !== null && (
                                                        <span>
                                                            {relativeIn(
                                                                nextMinutes,
                                                            )}
                                                        </span>
                                                    )}
                                            </div>
                                            <div className="sp-agenda-body">
                                                <a href={item.href}>
                                                    {item.name}
                                                </a>
                                                <span>{item.detail}</span>
                                            </div>
                                            <span
                                                className={`sp-tag${item.kind === 'visit' ? ' sp-tag-strong' : ''}`}
                                            >
                                                {item.kind === 'visit'
                                                    ? 'Visita'
                                                    : item.tag}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ol>
                        ) : (
                            <p className="sp-empty">
                                {searching
                                    ? 'Nenhum compromisso de hoje corresponde à busca.'
                                    : 'Nada agendado para hoje.'}
                            </p>
                        )}
                        <p className="sp-card-foot">
                            Próximos 7 dias:{' '}
                            {plural(
                                upcomingDays,
                                'agendamento',
                                'agendamentos',
                            )}
                        </p>
                    </section>

                    <section
                        aria-labelledby="sp-queue-title"
                        className="sp-card sp-queue"
                        id="fila"
                    >
                        <div className="sp-card-head">
                            <h2 id="sp-queue-title">Fila da unidade</h2>
                            <span className="sp-count">
                                {plural(queue.total, 'lead', 'leads')}
                            </span>
                        </div>
                        <p className="sp-card-sub">
                            Sem vendedor. O primeiro a assumir fica com o lead.
                        </p>
                        {queueShown.length ? (
                            <ul className="sp-queue-list">
                                {queueShown.map((lead) => (
                                    <li key={lead.id}>
                                        <div>
                                            <strong>
                                                {lead.responsavel ?? 'Sem nome'}
                                            </strong>
                                            <span>
                                                {[
                                                    lead.canal,
                                                    timeAgo(
                                                        lead.created_at,
                                                        now,
                                                    ),
                                                    units.length > 1
                                                        ? lead.unidade
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </div>
                                        <button
                                            className="sp-btn sp-btn-outline"
                                            onClick={() => claim(lead.id)}
                                            type="button"
                                        >
                                            Assumir
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="sp-empty">
                                {searching
                                    ? 'Nenhum lead da fila corresponde à busca.'
                                    : 'A fila está vazia.'}
                            </p>
                        )}
                        {!searching && queue.total > QUEUE_PREVIEW && (
                            <a className="sp-link" href="/leads">
                                Ver fila completa ({queue.total})
                            </a>
                        )}
                    </section>
                </div>

                <section
                    aria-labelledby="sp-followups-title"
                    className="sp-card"
                    id="follow-ups"
                >
                    <div className="sp-card-head">
                        <h2 id="sp-followups-title">Follow-ups pendentes</h2>
                        <span className="sp-card-sub">
                            Vencidos primeiro, depois os parados há mais tempo
                        </span>
                    </div>
                    <div className="sp-tabs-scroll">
                        <div
                            aria-label="Filtrar por período de espera"
                            className="sp-tabs"
                            role="tablist"
                        >
                            {WAIT_TABS.map((tab) => (
                                <button
                                    aria-selected={waitTab === tab.id}
                                    className={`sp-tab sp-tab-${tab.id}${waitTab === tab.id ? ' is-active' : ''}${tabCounts[tab.id] === 0 ? ' is-empty' : ''}`}
                                    key={tab.id}
                                    onClick={() => {
                                        setWaitTab(tab.id);
                                        setShowAllFollowUps(false);
                                    }}
                                    role="tab"
                                    type="button"
                                >
                                    <span className="sp-tab-icon">
                                        {tab.icon}
                                    </span>
                                    <span className="sp-tab-label">
                                        {tab.label}
                                    </span>
                                    <span className="sp-tab-count">
                                        {tabCounts[tab.id]}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                    {visibleFollowUps.length ? (
                        <div className="sp-followups">
                            {visibleFollowUps.map((lead) => {
                                const badge = followUpBadge(lead);

                                return (
                                    <article
                                        className={`sp-followup sp-followup-clickable${badge.tone ? ` sp-followup-${badge.tone}` : ''}`}
                                        key={lead.id}
                                        onClick={() => setViewingId(lead.id)}
                                    >
                                        <div className="sp-followup-head">
                                            <button
                                                className="sp-followup-open"
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    setViewingId(lead.id);
                                                }}
                                                type="button"
                                            >
                                                {lead.responsavel ?? 'Sem nome'}
                                            </button>
                                            <span className="sp-followup-badge">
                                                {badge.label}
                                            </span>
                                        </div>
                                        <span className="sp-followup-count">
                                            {lead.follow_ups_count === 1
                                                ? '1 follow-up'
                                                : `${lead.follow_ups_count} follow-ups`}
                                        </span>
                                        <div className="sp-followup-detail">
                                            {[
                                                [lead.aluno, lead.serie]
                                                    .filter(Boolean)
                                                    .join(', '),
                                                lead.status,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                        <div
                                            className="sp-followup-actions"
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                        >
                                            <button
                                                className="sp-btn sp-btn-primary sp-btn-sm"
                                                onClick={() =>
                                                    setCreatingForId(lead.id)
                                                }
                                                type="button"
                                            >
                                                Registrar contato
                                            </button>
                                            <button
                                                className="sp-btn sp-btn-secondary sp-btn-sm"
                                                onClick={() =>
                                                    setSchedulingId(lead.id)
                                                }
                                                type="button"
                                            >
                                                Agendar
                                            </button>
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    ) : (
                        <p className="sp-empty">
                            {searching
                                ? 'Nenhum follow-up corresponde à busca nesta aba.'
                                : waitTab === 'todos'
                                  ? 'Nenhum follow-up pendente.'
                                  : 'Nenhum follow-up nesta aba.'}
                        </p>
                    )}
                    {!searching &&
                        showAllFollowUps &&
                        tabFollowUps.length < tabTotal && (
                            <p className="sp-card-foot">
                                Mostrando os {tabFollowUps.length} primeiros de{' '}
                                {tabTotal}. Veja os demais em Leads.
                            </p>
                        )}
                    {!searching && tabFollowUps.length > FOLLOW_UP_PREVIEW && (
                        <button
                            className="sp-link sp-link-button"
                            onClick={() =>
                                setShowAllFollowUps((value) => !value)
                            }
                            type="button"
                        >
                            {showAllFollowUps
                                ? 'Mostrar menos'
                                : `Ver todos (${tabTotal})`}
                        </button>
                    )}
                </section>

                {creatingFor && (
                    <CreateFollowUpModal
                        lead={creatingFor}
                        onClose={() => setCreatingForId(null)}
                        onSchedule={() => setSchedulingId(creatingFor.id)}
                    />
                )}
                {viewing && (
                    <FollowUpsModal
                        lead={viewing}
                        onClose={() => setViewingId(null)}
                        onSchedule={() => setSchedulingId(viewing.id)}
                    />
                )}
                {/* Rendered last so it stacks over the follow-up modals (D-032). */}
                {scheduling && (
                    <ScheduleVisitModal
                        lead={scheduling}
                        onClose={() => setSchedulingId(null)}
                    />
                )}

                <section
                    aria-labelledby="sp-perf-title"
                    className="sp-performance"
                >
                    <h2 id="sp-perf-title">Meu desempenho e meta</h2>
                    <div className="sp-goals">
                        {activeGoals.length ? (
                            activeGoals.map((goal) => {
                                const progress =
                                    goal.goal && goal.goal > 0
                                        ? Math.min(
                                              100,
                                              (goal.sales / goal.goal) * 100,
                                          )
                                        : 0;
                                const pace =
                                    goal.expected_sales === null
                                        ? null
                                        : goal.sales - goal.expected_sales;

                                return (
                                    <div className="sp-goal" key={goal.id}>
                                        <div className="sp-goal-line">
                                            <span>
                                                {units.length > 1 &&
                                                    `${goal.unidade} · `}
                                                {formatDate(goal.inicio)} –{' '}
                                                {formatDate(goal.fim)} ·{' '}
                                                {goal.goal
                                                    ? `${goal.sales} de ${goal.goal} matrículas`
                                                    : `${goal.sales} matrículas · meta não definida`}
                                            </span>
                                            {pace !== null && (
                                                <strong
                                                    className={
                                                        pace >= 0
                                                            ? 'sp-pace-ok'
                                                            : 'sp-pace-behind'
                                                    }
                                                >
                                                    {pace >= 0
                                                        ? 'Dentro do ritmo'
                                                        : `${Math.abs(pace)} abaixo do ritmo`}
                                                </strong>
                                            )}
                                        </div>
                                        <div
                                            aria-label={`Meta atingida: ${progress.toFixed(0)}%`}
                                            className="sp-goal-bar"
                                            role="img"
                                        >
                                            <span
                                                style={{
                                                    width: `${progress}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                );
                            })
                        ) : (
                            <p className="sp-empty sp-empty-inline">
                                Nenhuma campanha em andamento.
                            </p>
                        )}
                    </div>
                    <dl className="sp-stats">
                        <div>
                            <dt>Leads</dt>
                            <dd>{performance.leads}</dd>
                        </div>
                        <div>
                            <dt>Agendamentos</dt>
                            <dd>{performance.appointments}</dd>
                        </div>
                        <div>
                            <dt>Visitas</dt>
                            <dd>{performance.visits}</dd>
                        </div>
                        <div>
                            <dt>Matrículas</dt>
                            <dd>{performance.sales}</dd>
                        </div>
                        <div>
                            <dt>Visita → matrícula</dt>
                            <dd>
                                {percentage(
                                    performance.sales,
                                    performance.visits,
                                )}
                            </dd>
                        </div>
                    </dl>
                    <a className="sp-link" href="/painel-vendas">
                        Ver painel de vendas
                    </a>
                </section>
            </div>
        </VisitLayout>
    );
}
