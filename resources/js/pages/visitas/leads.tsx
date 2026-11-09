import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import ListFilters from '@/components/visitas/ListFilters';
import type { FilterableRecord } from '@/components/visitas/ListFilters';
import RecordModal, { Field } from '@/components/visitas/RecordModal';
import StatusPill from '@/components/visitas/StatusPill';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Seller = {
    id: number;
    unidade_id: number;
    nome: string;
    is_current_user: boolean;
};
type Status = { id: number; nome: string };
type StatusHistory = {
    id: number;
    from_status: { id: number; nome: string } | null;
    to_status: { id: number; nome: string } | null;
    changed_by: { id: number; name: string } | null;
    changed_at: string;
};
type EditHistory = {
    id: number;
    user: { id: number; name: string } | null;
    changes: Array<{ field: string; old: string | null; new: string | null }>;
    created_at: string;
};
type Appointment = {
    id: number;
    data: string;
    horario: string;
    status: string;
    responsavel: string;
    telefone: string | null;
    aluno: string | null;
    observacoes: string | null;
    fonte: string;
};
type Visit = {
    id: number;
    data: string;
    horario: string | null;
    status: string;
    situacao: string;
    responsavel: string;
    telefone: string | null;
    aluno: string | null;
    observacoes: string | null;
    vendedor: { id: number; nome: string } | null;
};
type Lead = FilterableRecord & {
    unidade_id: number;
    unidade?: { id: number; nome: string } | null;
    vendedor?: { id: number; nome: string } | null;
    serie?: { id: number; nome: string } | null;
    agendamento?: Appointment | null;
    agendamentos: Appointment[];
    visitas: Visit[];
    status: { id: number; nome: string } | null;
    status_id: number | null;
    status_histories: StatusHistory[];
    edit_histories: EditHistory[];
    email: string | null;
    telefone_secundario: string | null;
    idade: number | null;
    nascimento: string | null;
    serie_id: number | null;
    ciclo_id: number | null;
    turno_id: number | null;
    ciclo: { id: number; nome: string } | null;
    turno: { id: number; nome: string } | null;
    retorno_data: string | null;
    data_matricula: string | null;
    desconto: string | null;
    origem: string;
    canal_contato: string | null;
    observacoes: string | null;
};
type LeadData = {
    unidade_id: string;
    vendedor_id: string;
    data: string;
    responsavel: string;
    telefone: string;
    telefone_secundario: string;
    email: string;
    aluno: string;
    idade: string;
    nascimento: string;
    serie_id: string;
    ciclo_id: string;
    turno_id: string;
    canal_mkt: string;
    canal_contato: string;
    status_id: string;
    retorno_data: string;
    observacoes: string;
    data_matricula: string;
    desconto: string;
};
type Props = {
    auth: Auth;
    leads: Lead[];
    units: Unit[];
    selectedUnitId: number | null;
    sellers: Seller[];
    currentSellerIds: number[];
    sellerScoped: boolean;
    statuses: Status[];
    series: Array<{ id: number; nome: string }>;
    cycles: Array<{ id: number; nome: string }>;
    shifts: Array<{ id: number; nome: string }>;
};

type DetailsTab = 'dados' | 'atendimentos' | 'historico';
type LeadEditTab = 'contato' | 'aluno' | 'funil';

const emptyLead: LeadData = {
    unidade_id: '',
    vendedor_id: '',
    data: '',
    responsavel: '',
    telefone: '',
    telefone_secundario: '',
    email: '',
    aluno: '',
    idade: '',
    nascimento: '',
    serie_id: '',
    ciclo_id: '',
    turno_id: '',
    canal_mkt: '',
    canal_contato: '',
    status_id: '',
    retorno_data: '',
    observacoes: '',
    data_matricula: '',
    desconto: '',
};

type SellerTransfer = {
    id: number;
    created_at: string;
    from: string | null;
    to: string | null;
    reason: string | null;
    user: string | null;
};

function sellerTransfers(lead: Lead): SellerTransfer[] {
    return lead.edit_histories.flatMap((history) => {
        const sellerChange = history.changes.find(
            (change) => change.field === 'Vendedor',
        );

        if (!sellerChange) {
            return [];
        }

        return [
            {
                id: history.id,
                created_at: history.created_at,
                from: sellerChange.old,
                to: sellerChange.new,
                reason:
                    history.changes.find(
                        (change) => change.field === 'Motivo da transferência',
                    )?.new ?? null,
                user: history.user?.name ?? null,
            },
        ];
    });
}

function dateLabel(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString(
        'pt-BR',
    );
}

export default function Leads({
    auth,
    leads,
    units,
    selectedUnitId,
    sellers,
    currentSellerIds,
    sellerScoped,
    statuses,
    series,
    cycles,
    shifts,
}: Props) {
    const [transferring, setTransferring] = useState<Lead | null>(null);
    const transferForm = useForm({ vendedor_id: '', motivo: '' });
    const [editing, setEditing] = useState<Lead | null>(null);
    const [leadModalOpen, setLeadModalOpen] = useState(false);
    const [leadEditTab, setLeadEditTab] = useState<LeadEditTab>('contato');
    const [detailsLead, setDetailsLead] = useState<Lead | null>(null);
    const [detailsTab, setDetailsTab] = useState<DetailsTab>('dados');
    const [scheduling, setScheduling] = useState<Lead | null>(null);
    const [openActionsId, setOpenActionsId] = useState<number | null>(null);
    const leadForm = useForm<LeadData>(emptyLead);
    const scheduleForm = useForm({ data: '', horario: '' });

    // Deep link from the seller panel: /leads?lead=ID&acao=editar|agendar|detalhes
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const action = params.get('acao');
        const leadId = Number(params.get('lead'));
        const target = leads.find((lead) => lead.id === leadId);

        if (action === 'novo') {
            openLead();
        } else if (!target) {
            return;
        } else if (action === 'editar') {
            openLead(target);
        } else if (action === 'agendar') {
            openSchedule(target);
        } else {
            openDetails(target);
        }

        params.delete('lead');
        params.delete('acao');
        const query = params.toString();
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${query ? `?${query}` : ''}`,
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (openActionsId === null) {
            return;
        }

        function handlePointerDown(event: PointerEvent) {
            const target = event.target;
            if (
                target instanceof Element &&
                !target.closest('.es-lead-actions')
            ) {
                setOpenActionsId(null);
            }
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setOpenActionsId(null);
            }
        }

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [openActionsId]);
    const linkedSeller = sellers.find(
        (seller) =>
            seller.is_current_user &&
            (!selectedUnitId || seller.unidade_id === selectedUnitId),
    );
    const unitForForm =
        linkedSeller?.unidade_id ?? selectedUnitId ?? units[0]?.id ?? '';
    const linkedSellerForForm = sellers.find(
        (seller) =>
            seller.is_current_user &&
            String(seller.unidade_id) === String(unitForForm),
    );
    const defaultStatus =
        statuses.find((status) => status.nome === 'Novo')?.id ??
        statuses[0]?.id ??
        '';

    function openDetails(lead: Lead) {
        setDetailsTab('dados');
        setDetailsLead(lead);
    }

    function openLead(lead: Lead | null = null) {
        setEditing(lead);
        setLeadEditTab('contato');
        setLeadModalOpen(true);
        leadForm.clearErrors();
        leadForm.setData(
            lead
                ? {
                      unidade_id: String(lead.unidade_id),
                      vendedor_id: lead.vendedor_id
                          ? String(lead.vendedor_id)
                          : '',
                      data: lead.data?.slice(0, 10) ?? '',
                      responsavel: lead.responsavel ?? '',
                      telefone: lead.telefone ?? '',
                      telefone_secundario: lead.telefone_secundario ?? '',
                      email: lead.email ?? '',
                      aluno: lead.aluno ?? '',
                      idade: lead.idade === null ? '' : String(lead.idade),
                      nascimento: lead.nascimento?.slice(0, 10) ?? '',
                      serie_id: lead.serie_id ? String(lead.serie_id) : '',
                      ciclo_id: lead.ciclo_id ? String(lead.ciclo_id) : '',
                      turno_id: lead.turno_id ? String(lead.turno_id) : '',
                      canal_mkt: lead.canal_mkt ?? '',
                      canal_contato: lead.canal_contato ?? '',
                      status_id: lead.status_id
                          ? String(lead.status_id)
                          : String(defaultStatus),
                      retorno_data: lead.retorno_data?.slice(0, 10) ?? '',
                      observacoes: lead.observacoes ?? '',
                      data_matricula: lead.data_matricula?.slice(0, 10) ?? '',
                      desconto:
                          lead.desconto === null ? '' : String(lead.desconto),
                  }
                : {
                      ...emptyLead,
                      unidade_id: String(unitForForm),
                      vendedor_id: linkedSellerForForm
                          ? String(linkedSellerForForm.id)
                          : '',
                      status_id: String(defaultStatus),
                  },
        );
    }

    function closeLead() {
        setLeadModalOpen(false);
        setEditing(null);
        leadForm.reset();
        leadForm.clearErrors();
    }

    function submitLead(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = {
            onSuccess: closeLead,
            onError: (errors: Record<string, string>) => {
                const errorFields = Object.keys(errors);
                const studentFields = [
                    'aluno',
                    'idade',
                    'nascimento',
                    'serie_id',
                    'ciclo_id',
                    'turno_id',
                    'canal_mkt',
                    'canal_contato',
                ];
                const funnelFields = [
                    'status_id',
                    'retorno_data',
                    'data_matricula',
                    'desconto',
                    'observacoes',
                ];

                if (
                    errorFields.some((field) => studentFields.includes(field))
                ) {
                    setLeadEditTab('aluno');
                } else if (
                    errorFields.some((field) => funnelFields.includes(field))
                ) {
                    setLeadEditTab('funil');
                } else {
                    setLeadEditTab('contato');
                }
            },
        };

        if (editing) {
            leadForm.put(`/leads/${editing.id}`, options);
        } else {
            leadForm.post('/leads', options);
        }
    }

    function openTransfer(lead: Lead) {
        setTransferring(lead);
        transferForm.reset();
        transferForm.clearErrors();
    }

    function closeTransfer() {
        setTransferring(null);
        transferForm.reset();
        transferForm.clearErrors();
    }

    function submitTransfer(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (transferring) {
            transferForm.post(`/leads/${transferring.id}/transfer`, {
                onSuccess: closeTransfer,
                preserveScroll: true,
            });
        }
    }

    function claimLead(lead: Lead) {
        router.post(`/leads/${lead.id}/claim`, {}, { preserveScroll: true });
    }

    function openSchedule(lead: Lead) {
        setScheduling(lead);
        scheduleForm.reset();
        scheduleForm.clearErrors();
    }

    function closeSchedule() {
        setScheduling(null);
        scheduleForm.reset();
        scheduleForm.clearErrors();
    }

    function submitSchedule(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (scheduling) {
            scheduleForm.post(`/leads/${scheduling.id}/schedule`, {
                onSuccess: closeSchedule,
            });
        }
    }

    return (
        <VisitLayout
            active="leads"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Leads"
            units={units}
        >
            <div className="es-header">
                <div>
                    <h1>Leads</h1>
                    <div className="es-sub">
                        {sellerScoped
                            ? 'Seus leads e a fila da unidade (leads sem vendedor)'
                            : 'Acompanhamento de leads em todas as etapas • todas as unidades'}
                    </div>
                </div>
                <button
                    className="btn btn-primary"
                    onClick={() => openLead()}
                    type="button"
                >
                    + Novo Lead
                </button>
            </div>
            <ListFilters
                page="leads"
                paginationPageSize={20}
                rows={leads}
                sellers={sellers}
                statuses={statuses.map((status) => status.nome)}
            >
                {(filteredLeads) => (
                    <div id="leads-results">
                        <div className="es-panel es-table-wrap es-leads-table-wrap">
                            <table className="es-table es-leads-table">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        {selectedUnitId === null &&
                                            units.length > 1 && (
                                                <th>Unidade</th>
                                            )}
                                        <th>Responsável / Aluno</th>
                                        <th>Contato</th>
                                        <th>Vendedor</th>
                                        <th>Situação</th>
                                        <th>Origem</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredLeads.map((lead) => (
                                        <tr
                                            aria-label={`Abrir detalhes da lead ${lead.responsavel ?? ''}`}
                                            key={lead.id}
                                            onClick={(event) => {
                                                const target = event.target;
                                                if (
                                                    target instanceof Element &&
                                                    target.closest(
                                                        'button, a, input, select, textarea',
                                                    )
                                                ) {
                                                    return;
                                                }

                                                openDetails(lead);
                                            }}
                                            onKeyDown={(event) => {
                                                if (
                                                    event.target ===
                                                        event.currentTarget &&
                                                    (event.key === 'Enter' ||
                                                        event.key === ' ')
                                                ) {
                                                    event.preventDefault();
                                                    openDetails(lead);
                                                }
                                            }}
                                            tabIndex={0}
                                        >
                                            <td>{dateLabel(lead.data)}</td>
                                            {selectedUnitId === null &&
                                                units.length > 1 && (
                                                    <td>
                                                        {lead.unidade?.nome ??
                                                            '—'}
                                                    </td>
                                                )}
                                            <td>
                                                <button
                                                    className="es-lead-name"
                                                    onClick={() =>
                                                        openDetails(lead)
                                                    }
                                                    type="button"
                                                >
                                                    <strong>
                                                        {lead.responsavel ||
                                                            '—'}
                                                    </strong>
                                                    <span className="es-sub">
                                                        {lead.aluno || ''}
                                                    </span>
                                                </button>
                                            </td>
                                            <td>{lead.telefone || '—'}</td>
                                            <td>
                                                {lead.vendedor?.nome ||
                                                    (sellerScoped
                                                        ? 'Fila da unidade'
                                                        : '—')}
                                            </td>
                                            <td>
                                                <StatusPill
                                                    status={
                                                        lead.status?.nome ??
                                                        'Novo'
                                                    }
                                                />
                                            </td>
                                            <td>{lead.canal_mkt || '—'}</td>
                                            <td className="es-leads-actions-cell">
                                                <div
                                                    className={`es-lead-actions${openActionsId === lead.id ? ' is-open' : ''}`}
                                                >
                                                    <button
                                                        aria-expanded={
                                                            openActionsId ===
                                                            lead.id
                                                        }
                                                        aria-haspopup="menu"
                                                        aria-controls={
                                                            openActionsId ===
                                                            lead.id
                                                                ? `lead-actions-${lead.id}`
                                                                : undefined
                                                        }
                                                        className="es-lead-actions-trigger"
                                                        onClick={() =>
                                                            setOpenActionsId(
                                                                openActionsId ===
                                                                    lead.id
                                                                    ? null
                                                                    : lead.id,
                                                            )
                                                        }
                                                        onKeyDown={(event) => {
                                                            if (
                                                                event.key ===
                                                                    'Escape' &&
                                                                openActionsId ===
                                                                    lead.id
                                                            ) {
                                                                setOpenActionsId(
                                                                    null,
                                                                );
                                                            }
                                                        }}
                                                        type="button"
                                                    >
                                                        <span>Ações</span>
                                                        <i
                                                            aria-hidden="true"
                                                            className="fas fa-chevron-down"
                                                        />
                                                    </button>
                                                    {openActionsId ===
                                                        lead.id && (
                                                        <div
                                                            className="es-lead-actions-menu"
                                                            id={`lead-actions-${lead.id}`}
                                                            onKeyDown={(
                                                                event,
                                                            ) => {
                                                                const items =
                                                                    Array.from(
                                                                        event.currentTarget.querySelectorAll<HTMLElement>(
                                                                            '[role="menuitem"]',
                                                                        ),
                                                                    );
                                                                const focusedIndex =
                                                                    items.findIndex(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item ===
                                                                            document.activeElement,
                                                                    );
                                                                let nextIndex =
                                                                    focusedIndex;

                                                                if (
                                                                    event.key ===
                                                                    'ArrowDown'
                                                                ) {
                                                                    nextIndex =
                                                                        (focusedIndex +
                                                                            1) %
                                                                        items.length;
                                                                } else if (
                                                                    event.key ===
                                                                    'ArrowUp'
                                                                ) {
                                                                    nextIndex =
                                                                        (focusedIndex -
                                                                            1 +
                                                                            items.length) %
                                                                        items.length;
                                                                } else if (
                                                                    event.key ===
                                                                    'Home'
                                                                ) {
                                                                    nextIndex = 0;
                                                                } else if (
                                                                    event.key ===
                                                                    'End'
                                                                ) {
                                                                    nextIndex =
                                                                        items.length -
                                                                        1;
                                                                } else if (
                                                                    event.key ===
                                                                    'Escape'
                                                                ) {
                                                                    setOpenActionsId(
                                                                        null,
                                                                    );
                                                                    event.currentTarget.parentElement
                                                                        ?.querySelector<HTMLButtonElement>(
                                                                            '.es-lead-actions-trigger',
                                                                        )
                                                                        ?.focus();
                                                                    return;
                                                                } else {
                                                                    return;
                                                                }

                                                                event.preventDefault();
                                                                items[
                                                                    nextIndex
                                                                ]?.focus();
                                                            }}
                                                            role="menu"
                                                        >
                                                            <button
                                                                className="es-lead-action"
                                                                onClick={() => {
                                                                    setOpenActionsId(
                                                                        null,
                                                                    );
                                                                    openDetails(
                                                                        lead,
                                                                    );
                                                                }}
                                                                role="menuitem"
                                                                type="button"
                                                            >
                                                                <i
                                                                    aria-hidden="true"
                                                                    className="fas fa-eye"
                                                                />
                                                                <span>
                                                                    Detalhes
                                                                </span>
                                                            </button>
                                                            <button
                                                                className="es-lead-action"
                                                                onClick={() => {
                                                                    setOpenActionsId(
                                                                        null,
                                                                    );
                                                                    openLead(
                                                                        lead,
                                                                    );
                                                                }}
                                                                role="menuitem"
                                                                type="button"
                                                            >
                                                                <i
                                                                    aria-hidden="true"
                                                                    className="fas fa-pen"
                                                                />
                                                                <span>
                                                                    Editar
                                                                </span>
                                                            </button>
                                                            <button
                                                                className="es-lead-action"
                                                                onClick={() => {
                                                                    setOpenActionsId(
                                                                        null,
                                                                    );
                                                                    openSchedule(
                                                                        lead,
                                                                    );
                                                                }}
                                                                role="menuitem"
                                                                type="button"
                                                            >
                                                                <i
                                                                    aria-hidden="true"
                                                                    className="fas fa-calendar-plus"
                                                                />
                                                                <span>
                                                                    Agendar
                                                                </span>
                                                            </button>
                                                            {sellerScoped &&
                                                                !lead.vendedor && (
                                                                    <button
                                                                        className="es-lead-action"
                                                                        onClick={() => {
                                                                            setOpenActionsId(
                                                                                null,
                                                                            );
                                                                            claimLead(
                                                                                lead,
                                                                            );
                                                                        }}
                                                                        role="menuitem"
                                                                        type="button"
                                                                    >
                                                                        <i
                                                                            aria-hidden="true"
                                                                            className="fas fa-hand"
                                                                        />
                                                                        <span>
                                                                            Assumir
                                                                            lead
                                                                        </span>
                                                                    </button>
                                                                )}
                                                            {(!sellerScoped ||
                                                                (lead.vendedor &&
                                                                    currentSellerIds.includes(
                                                                        lead
                                                                            .vendedor
                                                                            .id,
                                                                    ))) && (
                                                                <button
                                                                    className="es-lead-action"
                                                                    onClick={() => {
                                                                        setOpenActionsId(
                                                                            null,
                                                                        );
                                                                        openTransfer(
                                                                            lead,
                                                                        );
                                                                    }}
                                                                    role="menuitem"
                                                                    type="button"
                                                                >
                                                                    <i
                                                                        aria-hidden="true"
                                                                        className="fas fa-right-left"
                                                                    />
                                                                    <span>
                                                                        Transferir
                                                                    </span>
                                                                </button>
                                                            )}
                                                            {lead.agendamentos
                                                                .length > 0 && (
                                                                <a
                                                                    className="es-lead-action"
                                                                    href="/agendamentos"
                                                                    role="menuitem"
                                                                >
                                                                    <i
                                                                        aria-hidden="true"
                                                                        className="fas fa-calendar-check"
                                                                    />
                                                                    <span>
                                                                        Agendamentos
                                                                    </span>
                                                                </a>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {filteredLeads.length === 0 && (
                                        <tr>
                                            <td
                                                className="es-empty"
                                                colSpan={
                                                    selectedUnitId === null &&
                                                    units.length > 1
                                                        ? 8
                                                        : 7
                                                }
                                            >
                                                Nenhum registro encontrado para
                                                os filtros selecionados.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </ListFilters>
            {detailsLead && (
                <RecordModal
                    hideActions
                    onClose={() => setDetailsLead(null)}
                    onSubmit={(event) => event.preventDefault()}
                    processing={false}
                    title={`Lead: ${detailsLead.responsavel ?? 'Sem responsável'}`}
                >
                    <div className="es-lead-detail">
                        <div
                            aria-label="Seções dos detalhes da lead"
                            className="es-lead-tabs"
                            role="tablist"
                        >
                            <button
                                aria-controls="lead-tabpanel-dados"
                                aria-selected={detailsTab === 'dados'}
                                className={
                                    detailsTab === 'dados' ? 'active' : ''
                                }
                                id="lead-tab-dados"
                                onClick={() => setDetailsTab('dados')}
                                role="tab"
                                type="button"
                            >
                                Dados da lead
                            </button>
                            <button
                                aria-controls="lead-tabpanel-atendimentos"
                                aria-selected={detailsTab === 'atendimentos'}
                                className={
                                    detailsTab === 'atendimentos'
                                        ? 'active'
                                        : ''
                                }
                                id="lead-tab-atendimentos"
                                onClick={() => setDetailsTab('atendimentos')}
                                role="tab"
                                type="button"
                            >
                                Atendimentos
                            </button>
                            <button
                                aria-controls="lead-tabpanel-historico"
                                aria-selected={detailsTab === 'historico'}
                                className={
                                    detailsTab === 'historico' ? 'active' : ''
                                }
                                id="lead-tab-historico"
                                onClick={() => setDetailsTab('historico')}
                                role="tab"
                                type="button"
                            >
                                Histórico
                            </button>
                        </div>
                        {detailsTab === 'dados' && (
                            <section
                                aria-labelledby="lead-tab-dados"
                                id="lead-tabpanel-dados"
                                role="tabpanel"
                            >
                                <h3>Dados da lead</h3>
                                <dl>
                                    <div>
                                        <dt>Aluno</dt>
                                        <dd>{detailsLead.aluno || '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>Unidade</dt>
                                        <dd>
                                            {detailsLead.unidade?.nome || '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Situação atual</dt>
                                        <dd>
                                            {detailsLead.status?.nome || '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Data de entrada</dt>
                                        <dd>{dateLabel(detailsLead.data)}</dd>
                                    </div>
                                    <div>
                                        <dt>Vendedor</dt>
                                        <dd>
                                            {detailsLead.vendedor?.nome || '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Origem</dt>
                                        <dd>{detailsLead.origem || '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>Telefone</dt>
                                        <dd>{detailsLead.telefone || '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>Telefone secundário</dt>
                                        <dd>
                                            {detailsLead.telefone_secundario ||
                                                '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>E-mail</dt>
                                        <dd>{detailsLead.email || '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>Série / ciclo / turno</dt>
                                        <dd>
                                            {[
                                                detailsLead.serie?.nome,
                                                detailsLead.ciclo?.nome,
                                                detailsLead.turno?.nome,
                                            ]
                                                .filter(Boolean)
                                                .join(' / ') || '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Data de nascimento</dt>
                                        <dd>
                                            {dateLabel(detailsLead.nascimento)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Idade</dt>
                                        <dd>{detailsLead.idade ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>Canal de marketing</dt>
                                        <dd>{detailsLead.canal_mkt || '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>Canal de contato</dt>
                                        <dd>
                                            {detailsLead.canal_contato || '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Data de retorno</dt>
                                        <dd>
                                            {dateLabel(
                                                detailsLead.retorno_data,
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Data de matrícula</dt>
                                        <dd>
                                            {dateLabel(
                                                detailsLead.data_matricula,
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Desconto</dt>
                                        <dd>
                                            {detailsLead.desconto === null
                                                ? '—'
                                                : `${detailsLead.desconto}%`}
                                        </dd>
                                    </div>
                                    <div className="es-lead-detail-wide">
                                        <dt>Observações</dt>
                                        <dd>
                                            {detailsLead.observacoes || '—'}
                                        </dd>
                                    </div>
                                </dl>
                            </section>
                        )}
                        {detailsTab === 'atendimentos' && (
                            <div
                                aria-labelledby="lead-tab-atendimentos"
                                id="lead-tabpanel-atendimentos"
                                role="tabpanel"
                            >
                                <section>
                                    <h3>Agendamentos</h3>
                                    {detailsLead.agendamentos.length > 0 ? (
                                        <ul>
                                            {detailsLead.agendamentos.map(
                                                (appointment) => (
                                                    <li key={appointment.id}>
                                                        {dateLabel(
                                                            appointment.data,
                                                        )}
                                                        {' às '}
                                                        {appointment.horario?.slice(
                                                            0,
                                                            5,
                                                        ) || '—'}
                                                        {' · '}
                                                        {appointment.status}
                                                        {appointment.fonte
                                                            ? ` (${appointment.fonte})`
                                                            : ''}
                                                        {appointment.observacoes
                                                            ? ` · ${appointment.observacoes}`
                                                            : ''}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : (
                                        <p>Nenhum agendamento registrado.</p>
                                    )}
                                </section>

                                <section>
                                    <h3>Atendimentos e visitas</h3>
                                    {detailsLead.visitas.length > 0 ? (
                                        <ul>
                                            {detailsLead.visitas.map(
                                                (visit) => (
                                                    <li key={visit.id}>
                                                        {dateLabel(visit.data)}
                                                        {visit.horario
                                                            ? ` às ${visit.horario.slice(0, 5)}`
                                                            : ''}
                                                        {' · '}
                                                        {visit.status} /{' '}
                                                        {visit.situacao}
                                                        {' · '}
                                                        {visit.vendedor?.nome ||
                                                            'Sem vendedor'}
                                                        {visit.observacoes
                                                            ? ` · ${visit.observacoes}`
                                                            : ''}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : (
                                        <p>
                                            Nenhum atendimento ou visita
                                            registrado.
                                        </p>
                                    )}
                                </section>
                            </div>
                        )}

                        {detailsTab === 'historico' && (
                            <div
                                aria-labelledby="lead-tab-historico"
                                id="lead-tabpanel-historico"
                                role="tabpanel"
                            >
                                <section>
                                    <h3>Transferências de vendedor</h3>
                                    {sellerTransfers(detailsLead).length > 0 ? (
                                        <ol>
                                            {sellerTransfers(detailsLead).map(
                                                (transfer) => (
                                                    <li key={transfer.id}>
                                                        <strong>
                                                            {new Date(
                                                                transfer.created_at,
                                                            ).toLocaleString(
                                                                'pt-BR',
                                                            )}
                                                        </strong>
                                                        {' · '}
                                                        {transfer.from ??
                                                            'Fila da unidade'}{' '}
                                                        →{' '}
                                                        {transfer.to ??
                                                            'Fila da unidade'}
                                                        {' • por '}
                                                        {transfer.user ??
                                                            'Sistema ou usuário removido'}
                                                        {transfer.reason && (
                                                            <div className="es-sub">
                                                                Motivo:{' '}
                                                                {
                                                                    transfer.reason
                                                                }
                                                            </div>
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    ) : (
                                        <p>Nenhuma transferência registrada.</p>
                                    )}
                                </section>

                                <section>
                                    <h3>Histórico de alterações</h3>
                                    {detailsLead.edit_histories.length > 0 ? (
                                        <ol>
                                            {detailsLead.edit_histories.map(
                                                (history) => (
                                                    <li key={history.id}>
                                                        <strong>
                                                            {new Date(
                                                                history.created_at,
                                                            ).toLocaleString(
                                                                'pt-BR',
                                                            )}
                                                        </strong>
                                                        {' · '}
                                                        {history.user?.name ||
                                                            'Sistema ou usuário removido'}
                                                        <ul>
                                                            {history.changes.map(
                                                                (
                                                                    change,
                                                                    index,
                                                                ) => (
                                                                    <li
                                                                        key={`${history.id}-${index}`}
                                                                    >
                                                                        {
                                                                            change.field
                                                                        }
                                                                        :{' '}
                                                                        {change.old ??
                                                                            'Vazio'}{' '}
                                                                        →{' '}
                                                                        {change.new ??
                                                                            'Vazio'}
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    ) : (
                                        <p>Nenhuma edição registrada.</p>
                                    )}
                                </section>

                                <section>
                                    <h3>Histórico de situação</h3>
                                    {detailsLead.status_histories.length > 0 ? (
                                        <ol>
                                            {detailsLead.status_histories.map(
                                                (history) => (
                                                    <li key={history.id}>
                                                        <strong>
                                                            {new Date(
                                                                history.changed_at,
                                                            ).toLocaleString(
                                                                'pt-BR',
                                                            )}
                                                        </strong>
                                                        {' · '}
                                                        {history.from_status
                                                            ?.nome ?? 'Início'}
                                                        {' → '}
                                                        {history.to_status
                                                            ?.nome ??
                                                            'Sem situação'}
                                                        {' · '}
                                                        {history.changed_by
                                                            ?.name ??
                                                            'Sistema/API ou registro inicial'}
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    ) : (
                                        <p>
                                            Nenhuma alteração de situação
                                            registrada.
                                        </p>
                                    )}
                                </section>
                            </div>
                        )}

                        <div className="es-lead-detail-actions">
                            <button
                                className="btn btn-primary"
                                onClick={() => {
                                    const lead = detailsLead;
                                    setDetailsLead(null);
                                    openLead(lead);
                                }}
                                type="button"
                            >
                                Editar lead e situação
                            </button>
                        </div>
                    </div>
                </RecordModal>
            )}
            {leadModalOpen && (
                <RecordModal
                    onClose={closeLead}
                    onSubmit={submitLead}
                    processing={leadForm.processing}
                    title={editing ? 'Editar lead' : 'Novo lead'}
                >
                    <div className="es-lead-detail">
                        <div
                            aria-label="Seções do formulário da lead"
                            className="es-lead-tabs"
                            role="tablist"
                        >
                            <button
                                aria-controls="lead-edit-panel-contato"
                                aria-selected={leadEditTab === 'contato'}
                                className={
                                    leadEditTab === 'contato' ? 'active' : ''
                                }
                                id="lead-edit-tab-contato"
                                onClick={() => setLeadEditTab('contato')}
                                role="tab"
                                type="button"
                            >
                                Contato
                            </button>
                            <button
                                aria-controls="lead-edit-panel-aluno"
                                aria-selected={leadEditTab === 'aluno'}
                                className={
                                    leadEditTab === 'aluno' ? 'active' : ''
                                }
                                id="lead-edit-tab-aluno"
                                onClick={() => setLeadEditTab('aluno')}
                                role="tab"
                                type="button"
                            >
                                Aluno e origem
                            </button>
                            <button
                                aria-controls="lead-edit-panel-funil"
                                aria-selected={leadEditTab === 'funil'}
                                className={
                                    leadEditTab === 'funil' ? 'active' : ''
                                }
                                id="lead-edit-tab-funil"
                                onClick={() => setLeadEditTab('funil')}
                                role="tab"
                                type="button"
                            >
                                Funil e acompanhamento
                            </button>
                        </div>
                        {leadEditTab === 'contato' && (
                            <div
                                aria-labelledby="lead-edit-tab-contato"
                                className="es-lead-edit-fields"
                                id="lead-edit-panel-contato"
                                role="tabpanel"
                            >
                                <Field
                                    error={leadForm.errors.unidade_id}
                                    label="Unidade"
                                >
                                    <select
                                        onChange={(event) => {
                                            const nextUnitId =
                                                event.target.value;
                                            const ownSeller = sellers.find(
                                                (seller) =>
                                                    seller.is_current_user &&
                                                    String(
                                                        seller.unidade_id,
                                                    ) === nextUnitId,
                                            );
                                            leadForm.setData((current) => ({
                                                ...current,
                                                unidade_id: nextUnitId,
                                                vendedor_id: ownSeller
                                                    ? String(ownSeller.id)
                                                    : '',
                                            }));
                                        }}
                                        value={leadForm.data.unidade_id}
                                    >
                                        {units.map((unit) => (
                                            <option
                                                key={unit.id}
                                                value={unit.id}
                                            >
                                                {unit.nome}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    error={leadForm.errors.vendedor_id}
                                    label="Vendedor"
                                >
                                    <select
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'vendedor_id',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.vendedor_id}
                                    >
                                        <option value="">Não atribuído</option>
                                        {sellers
                                            .filter(
                                                (seller) =>
                                                    String(
                                                        seller.unidade_id,
                                                    ) ===
                                                    leadForm.data.unidade_id,
                                            )
                                            .map((seller) => (
                                                <option
                                                    key={seller.id}
                                                    value={seller.id}
                                                >
                                                    {seller.nome}
                                                </option>
                                            ))}
                                    </select>
                                </Field>
                                <Field
                                    error={leadForm.errors.data}
                                    label="Data do contato"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'data',
                                                event.target.value,
                                            )
                                        }
                                        type="date"
                                        value={leadForm.data.data}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.responsavel}
                                    label="Responsável *"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'responsavel',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        value={leadForm.data.responsavel}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.telefone}
                                    label="Telefone"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'telefone',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.telefone}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.telefone_secundario}
                                    label="Telefone secundário"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'telefone_secundario',
                                                event.target.value,
                                            )
                                        }
                                        value={
                                            leadForm.data.telefone_secundario
                                        }
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.email}
                                    label="E-mail"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                        type="email"
                                        value={leadForm.data.email}
                                    />
                                </Field>
                            </div>
                        )}
                        {leadEditTab === 'aluno' && (
                            <div
                                aria-labelledby="lead-edit-tab-aluno"
                                className="es-lead-edit-fields"
                                id="lead-edit-panel-aluno"
                                role="tabpanel"
                            >
                                <Field
                                    error={leadForm.errors.aluno}
                                    label="Aluno"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'aluno',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.aluno}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.idade}
                                    label="Idade"
                                >
                                    <input
                                        min="0"
                                        max="120"
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'idade',
                                                event.target.value,
                                            )
                                        }
                                        type="number"
                                        value={leadForm.data.idade}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.nascimento}
                                    label="Data de nascimento"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'nascimento',
                                                event.target.value,
                                            )
                                        }
                                        type="date"
                                        value={leadForm.data.nascimento}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.serie_id}
                                    label="Série"
                                >
                                    <select
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'serie_id',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.serie_id}
                                    >
                                        <option value="">Selecione</option>
                                        {series.map((serie) => (
                                            <option
                                                key={serie.id}
                                                value={serie.id}
                                            >
                                                {serie.nome}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    error={leadForm.errors.ciclo_id}
                                    label="Ciclo"
                                >
                                    <select
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'ciclo_id',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.ciclo_id}
                                    >
                                        <option value="">Selecione</option>
                                        {cycles.map((cycle) => (
                                            <option
                                                key={cycle.id}
                                                value={cycle.id}
                                            >
                                                {cycle.nome}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    error={leadForm.errors.turno_id}
                                    label="Turno"
                                >
                                    <select
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'turno_id',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.turno_id}
                                    >
                                        <option value="">Selecione</option>
                                        {shifts.map((shift) => (
                                            <option
                                                key={shift.id}
                                                value={shift.id}
                                            >
                                                {shift.nome}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    error={leadForm.errors.canal_mkt}
                                    label="Canal de marketing"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'canal_mkt',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.canal_mkt}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.canal_contato}
                                    label="Canal de contato"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'canal_contato',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.canal_contato}
                                    />
                                </Field>
                            </div>
                        )}
                        {leadEditTab === 'funil' && (
                            <div
                                aria-labelledby="lead-edit-tab-funil"
                                className="es-lead-edit-fields"
                                id="lead-edit-panel-funil"
                                role="tabpanel"
                            >
                                <Field
                                    error={leadForm.errors.status_id}
                                    label="Status"
                                >
                                    <select
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'status_id',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.status_id}
                                    >
                                        {statuses.map((status) => (
                                            <option
                                                key={status.id}
                                                value={status.id}
                                            >
                                                {status.nome}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    error={leadForm.errors.retorno_data}
                                    label="Data de retorno"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'retorno_data',
                                                event.target.value,
                                            )
                                        }
                                        type="date"
                                        value={leadForm.data.retorno_data}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.data_matricula}
                                    label="Data de matrícula"
                                >
                                    <input
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'data_matricula',
                                                event.target.value,
                                            )
                                        }
                                        type="date"
                                        value={leadForm.data.data_matricula}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.desconto}
                                    label="Desconto (%)"
                                >
                                    <input
                                        max="100"
                                        min="0"
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'desconto',
                                                event.target.value,
                                            )
                                        }
                                        type="number"
                                        value={leadForm.data.desconto}
                                    />
                                </Field>
                                <Field
                                    error={leadForm.errors.observacoes}
                                    label="Observações"
                                >
                                    <textarea
                                        onChange={(event) =>
                                            leadForm.setData(
                                                'observacoes',
                                                event.target.value,
                                            )
                                        }
                                        value={leadForm.data.observacoes}
                                    />
                                </Field>
                            </div>
                        )}
                    </div>
                </RecordModal>
            )}
            {transferring !== null && (
                <RecordModal
                    onClose={closeTransfer}
                    onSubmit={submitTransfer}
                    processing={transferForm.processing}
                    submitLabel="Transferir"
                    title={`Transferir lead: ${transferring.responsavel ?? ''}`}
                >
                    <p className="es-schedule-note">
                        Vendedor atual:{' '}
                        {transferring.vendedor?.nome ?? 'Fila da unidade'}. A
                        transferência fica registrada no histórico do lead, e os
                        agendamentos em aberto passam para o novo vendedor.
                    </p>
                    <Field
                        error={transferForm.errors.vendedor_id}
                        label="Novo vendedor"
                    >
                        <select
                            onChange={(event) =>
                                transferForm.setData(
                                    'vendedor_id',
                                    event.target.value,
                                )
                            }
                            required
                            value={transferForm.data.vendedor_id}
                        >
                            <option value="">Selecione</option>
                            {sellers
                                .filter(
                                    (seller) =>
                                        seller.unidade_id ===
                                            transferring.unidade_id &&
                                        seller.id !== transferring.vendedor?.id,
                                )
                                .map((seller) => (
                                    <option key={seller.id} value={seller.id}>
                                        {seller.nome}
                                    </option>
                                ))}
                        </select>
                    </Field>
                    <Field
                        error={transferForm.errors.motivo}
                        label="Motivo (opcional)"
                    >
                        <textarea
                            maxLength={500}
                            onChange={(event) =>
                                transferForm.setData(
                                    'motivo',
                                    event.target.value,
                                )
                            }
                            value={transferForm.data.motivo}
                        />
                    </Field>
                </RecordModal>
            )}
            {scheduling !== null && (
                <RecordModal
                    onClose={closeSchedule}
                    onSubmit={submitSchedule}
                    processing={scheduleForm.processing}
                    submitLabel="Agendar"
                    title={`Agendar visita: ${scheduling.responsavel ?? ''}`}
                >
                    <Field error={scheduleForm.errors.data} label="Data">
                        <input
                            onChange={(event) =>
                                scheduleForm.setData('data', event.target.value)
                            }
                            required
                            type="date"
                            value={scheduleForm.data.data}
                        />
                    </Field>
                    <Field error={scheduleForm.errors.horario} label="Horário">
                        <input
                            onChange={(event) =>
                                scheduleForm.setData(
                                    'horario',
                                    event.target.value,
                                )
                            }
                            required
                            type="time"
                            value={scheduleForm.data.horario}
                        />
                    </Field>
                    <p className="es-schedule-note">
                        O agendamento ficará atribuído a{' '}
                        {scheduling.vendedor?.nome ?? 'Não atribuído'}.
                    </p>
                </RecordModal>
            )}
        </VisitLayout>
    );
}
