import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Metrics = {
    label: string;
    leads: number;
    appointments: number;
    visits: number;
    sales: number;
};
type Seller = {
    id: number;
    unidade_id: number;
    unidade: string;
    nome: string;
    ativo: boolean;
    user_id: number | null;
    user_name: string | null;
    leads: number;
    status_counts: Record<number, number>;
    follow_up_counts: {
        atendendo: number;
        em_negociacao: number;
        agendado: number;
    };
    metrics: Omit<Metrics, 'label'>;
};
type Enrollment = {
    id: string;
    lead_id: number | null;
    data: string | null;
    aluno: string | null;
    responsavel: string | null;
    status: string;
};
type FollowUpLead = {
    id: number;
    data: string | null;
    aluno: string | null;
    responsavel: string;
    telefone: string | null;
    status: string;
};
type LeadDetail = Omit<FollowUpLead, 'status'> & {
    status: { id: number; nome: string } | null;
    unidade: Unit | null;
    vendedor: { id: number; nome: string } | null;
    serie: { id: number; nome: string } | null;
    ciclo: { id: number; nome: string } | null;
    turno: { id: number; nome: string } | null;
    status_id: number | null;
    email: string | null;
    telefone_secundario: string | null;
    idade: number | null;
    nascimento: string | null;
    retorno_data: string | null;
    data_matricula: string | null;
    desconto: string | null;
    origem: string;
    canal_mkt: string | null;
    canal_contato: string | null;
    observacoes: string | null;
    agendamentos: Array<{
        id: number;
        data: string;
        horario: string;
        status: string;
        fonte: string;
        observacoes: string | null;
    }>;
    visitas: Array<{
        id: number;
        data: string;
        horario: string | null;
        status: string;
        situacao: string;
        observacoes: string | null;
        vendedor: { id: number; nome: string } | null;
    }>;
    status_histories: Array<{
        id: number;
        from_status: { id: number; nome: string } | null;
        to_status: { id: number; nome: string } | null;
        changed_by: { id: number; name: string } | null;
        changed_at: string;
    }>;
    edit_histories: Array<{
        id: number;
        user: { id: number; name: string } | null;
        changes: Array<{
            field: string;
            old: string | null;
            new: string | null;
        }>;
        created_at: string;
    }>;
};
type LeadDetailTab = 'dados' | 'atendimentos' | 'historico';
type Props = {
    auth: Auth;
    units: Unit[];
    selectedUnitId: number | null;
    snapshotDate: string;
    today: string;
    historyStartDate: string;
    metrics: Metrics;
    sellers: Seller[];
    selectedSellerId: number | null;
    selectedLead: LeadDetail | null;
    sellerDetail:
        | (Seller & {
              follow_up_leads: FollowUpLead[];
              enrollments: Enrollment[];
          })
        | null;
};

function percentage(value: number, total: number): string {
    return total
        ? `${((value / total) * 100).toFixed(1).replace('.', ',')}%`
        : '0,0%';
}

function formatDate(value: string | null): string {
    const date = value?.slice(0, 10);
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.split('-');

    return year && month && day ? `${day}/${month}/${year}` : '—';
}

export default function SalesDashboard({
    auth,
    units,
    selectedUnitId,
    snapshotDate,
    today,
    historyStartDate,
    metrics,
    sellers,
    selectedSellerId,
    selectedLead,
    sellerDetail,
}: Props) {
    const [followUpPage, setFollowUpPage] = useState(1);
    const [enrollmentPage, setEnrollmentPage] = useState(1);
    const [leadDetailTab, setLeadDetailTab] = useState<LeadDetailTab>('dados');
    const pageSize = 10;
    const sellerGroups = units
        .map((unit) => ({
            ...unit,
            sellers: sellers.filter((seller) => seller.unidade_id === unit.id),
        }))
        .filter((group) => group.sellers.length > 0);
    const followUpPageCount = Math.ceil(
        (sellerDetail?.follow_up_leads.length ?? 0) / pageSize,
    );
    const visibleFollowUpLeads =
        sellerDetail?.follow_up_leads.slice(
            (followUpPage - 1) * pageSize,
            followUpPage * pageSize,
        ) ?? [];
    const enrollmentPageCount = Math.ceil(
        (sellerDetail?.enrollments.length ?? 0) / pageSize,
    );
    const visibleEnrollments =
        sellerDetail?.enrollments.slice(
            (enrollmentPage - 1) * pageSize,
            enrollmentPage * pageSize,
        ) ?? [];

    function sellerUrl(sellerId?: number): string {
        const query = new URLSearchParams();
        if (selectedUnitId) {
            query.set('unidade_id', String(selectedUnitId));
        }
        query.set('snapshot_date', snapshotDate);
        if (sellerId) {
            query.set('seller_id', String(sellerId));
        }

        return `/painel-vendas?${query.toString()}`;
    }

    function openSeller(sellerId: number) {
        if (selectedSellerId === sellerId) {
            closeSellerDetail();

            return;
        }

        router.get(sellerUrl(sellerId), {}, { preserveScroll: true });
    }

    function closeSellerDetail() {
        router.get(sellerUrl(), {}, { preserveScroll: true, replace: true });
    }

    function openLeadDetails(leadId: number | null) {
        if (leadId === null || selectedSellerId === null) {
            return;
        }

        router.get(
            '/painel-vendas',
            {
                unidade_id: selectedUnitId ?? '',
                seller_id: selectedSellerId,
                snapshot_date: snapshotDate,
                lead_id: leadId,
            },
            { preserveScroll: true },
        );
    }

    function closeLeadDetails() {
        setLeadDetailTab('dados');
        router.get(
            sellerUrl(selectedSellerId ?? undefined),
            {},
            {
                preserveScroll: true,
                replace: true,
            },
        );
    }

    useEffect(() => {
        setFollowUpPage(1);
        setEnrollmentPage(1);
    }, [selectedSellerId, snapshotDate]);

    useEffect(() => {
        if (!sellerDetail) {
            return;
        }

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                const url = new URL(window.location.href);
                if (selectedLead) {
                    url.searchParams.delete('lead_id');
                } else {
                    url.searchParams.delete('seller_id');
                }
                router.get(
                    `${url.pathname}${url.search}`,
                    {},
                    {
                        preserveScroll: true,
                        replace: true,
                    },
                );
            }
        };
        window.addEventListener('keydown', closeOnEscape);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', closeOnEscape);
        };
    }, [selectedSellerId, selectedLead?.id]);

    return (
        <VisitLayout
            active="painel-vendas"
            auth={auth}
            preservedQuery={{ snapshot_date: snapshotDate }}
            selectedUnitId={selectedUnitId}
            title="Painel de Vendas"
            units={units}
        >
            <div className="es-header">
                <div>
                    <h1>Painel de Vendas</h1>
                    <div className="es-sub">
                        Acompanhamento histórico do funil comercial por vendedor
                        e unidade
                    </div>
                </div>
                <a className="btn btn-secondary" href="/vendedores">
                    <i className="fas fa-users" aria-hidden="true" /> Equipe de
                    vendedores
                </a>
            </div>
            <form
                action="/painel-vendas"
                className="es-panel es-report-filters"
                method="get"
            >
                {selectedUnitId && (
                    <input
                        name="unidade_id"
                        type="hidden"
                        value={selectedUnitId}
                    />
                )}
                <label>
                    Ver funil em
                    <input
                        defaultValue={snapshotDate}
                        min={historyStartDate}
                        max={today}
                        name="snapshot_date"
                        required
                        type="date"
                    />
                </label>
                <button className="btn btn-primary" type="submit">
                    Aplicar data
                </button>
            </form>
            <section className="es-grid es-sales-overview">
                <div className="es-stat">
                    <small>Leads</small>
                    <strong>{metrics.leads}</strong>
                </div>
                <div className="es-stat">
                    <small>Agendamentos</small>
                    <strong>{metrics.appointments}</strong>
                </div>
                <div className="es-stat">
                    <small>Visitas</small>
                    <strong>{metrics.visits}</strong>
                </div>
                <div className="es-stat">
                    <small>Matrículas comerciais</small>
                    <strong>{metrics.sales}</strong>
                </div>
                <div className="es-stat">
                    <small>Conversão visita → matrícula</small>
                    <strong>{percentage(metrics.sales, metrics.visits)}</strong>
                </div>
            </section>
            <section className="es-panel">
                <div className="es-seller-panel-heading">
                    <div>
                        <h2>Funil dos vendedores por unidade</h2>
                        <p className="es-sub">
                            Retrato das etapas ao final de {snapshotDate},
                            considerando as mudanças registradas até essa data.
                            Selecione um vendedor para ver os detalhes.
                        </p>
                    </div>
                </div>
                {sellerGroups.map((group) => (
                    <div className="es-seller-performance" key={group.id}>
                        <h3 className="es-seller-unit-heading">{group.nome}</h3>
                        <div className="es-table-wrap">
                            <table className="es-table es-sales-seller-table">
                                <thead>
                                    <tr>
                                        <th>Vendedor</th>
                                        <th>Atendendo</th>
                                        <th>Em negociação</th>
                                        <th>Agendado</th>
                                        <th>Matrículas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {group.sellers.map((seller) => (
                                        <tr
                                            aria-label={`Detalhes de ${seller.nome}`}
                                            className={
                                                selectedSellerId === seller.id
                                                    ? 'selected'
                                                    : undefined
                                            }
                                            key={seller.id}
                                            onClick={() =>
                                                openSeller(seller.id)
                                            }
                                            onKeyDown={(event) => {
                                                if (
                                                    event.key === 'Enter' ||
                                                    event.key === ' '
                                                ) {
                                                    event.preventDefault();
                                                    openSeller(seller.id);
                                                }
                                            }}
                                            role="button"
                                            tabIndex={0}
                                            aria-haspopup="dialog"
                                            aria-expanded={
                                                selectedSellerId === seller.id
                                            }
                                        >
                                            <td>
                                                {seller.nome}{' '}
                                                <span
                                                    className={`es-pill ${seller.ativo ? 'green' : 'red'}`}
                                                >
                                                    {seller.ativo
                                                        ? 'Ativo'
                                                        : 'Inativo'}
                                                </span>
                                            </td>
                                            <td>
                                                {
                                                    seller.follow_up_counts
                                                        .atendendo
                                                }
                                            </td>
                                            <td>
                                                {
                                                    seller.follow_up_counts
                                                        .em_negociacao
                                                }
                                            </td>
                                            <td>
                                                {
                                                    seller.follow_up_counts
                                                        .agendado
                                                }
                                            </td>
                                            <td>{seller.metrics.sales}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                ))}
                {sellers.length === 0 && (
                    <p className="es-empty">
                        Nenhum vendedor disponível para este filtro.
                    </p>
                )}
            </section>
            {sellerDetail && (
                <div className="es-modal" role="presentation">
                    <button
                        aria-label="Fechar detalhamento do vendedor"
                        className="es-modal-backdrop"
                        onClick={closeSellerDetail}
                        tabIndex={-1}
                        type="button"
                    />
                    <section
                        aria-labelledby="seller-detail-title"
                        aria-modal="true"
                        className="es-modal-card es-seller-detail-modal"
                        role="dialog"
                    >
                        <div className="es-modal-head">
                            <div>
                                <h2 id="seller-detail-title">
                                    {sellerDetail.nome}
                                </h2>
                                <p>{sellerDetail.unidade}</p>
                            </div>
                            <button
                                aria-label="Fechar detalhamento"
                                className="es-close"
                                onClick={closeSellerDetail}
                                type="button"
                            >
                                ×
                            </button>
                        </div>
                        <div className="es-seller-detail-content">
                            <section>
                                <h3>Estatísticas individuais</h3>
                                <div className="es-seller-metrics">
                                    <div>
                                        <small>Leads</small>
                                        <strong>
                                            {sellerDetail.metrics.leads}
                                        </strong>
                                    </div>
                                    <div>
                                        <small>Agendamentos</small>
                                        <strong>
                                            {sellerDetail.metrics.appointments}
                                        </strong>
                                    </div>
                                    <div>
                                        <small>Visitas atendidas</small>
                                        <strong>
                                            {sellerDetail.metrics.visits}
                                        </strong>
                                    </div>
                                    <div>
                                        <small>Matrículas</small>
                                        <strong>
                                            {sellerDetail.metrics.sales}
                                        </strong>
                                    </div>
                                    <div>
                                        <small>Lead → Matrícula</small>
                                        <strong>
                                            {percentage(
                                                sellerDetail.metrics.sales,
                                                sellerDetail.metrics.leads,
                                            )}
                                        </strong>
                                    </div>
                                    <div>
                                        <small>Visita → Matrícula</small>
                                        <strong>
                                            {percentage(
                                                sellerDetail.metrics.sales,
                                                sellerDetail.metrics.visits,
                                            )}
                                        </strong>
                                    </div>
                                </div>
                            </section>
                            <section>
                                <h3>
                                    Leads em acompanhamento em {snapshotDate}
                                </h3>
                                {sellerDetail.follow_up_leads.length > 0 ? (
                                    <>
                                        <div className="es-table-wrap">
                                            <table className="es-table">
                                                <thead>
                                                    <tr>
                                                        <th>Data</th>
                                                        <th>Aluno</th>
                                                        <th>Contato</th>
                                                        <th>Telefone</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {visibleFollowUpLeads.map(
                                                        (lead) => (
                                                            <tr
                                                                aria-label={`Detalhes do lead de ${lead.responsavel}`}
                                                                key={lead.id}
                                                                onClick={() =>
                                                                    openLeadDetails(
                                                                        lead.id,
                                                                    )
                                                                }
                                                                onKeyDown={(
                                                                    event,
                                                                ) => {
                                                                    if (
                                                                        event.key ===
                                                                            'Enter' ||
                                                                        event.key ===
                                                                            ' '
                                                                    ) {
                                                                        event.preventDefault();
                                                                        openLeadDetails(
                                                                            lead.id,
                                                                        );
                                                                    }
                                                                }}
                                                                role="link"
                                                                tabIndex={0}
                                                            >
                                                                <td>
                                                                    {formatDate(
                                                                        lead.data,
                                                                    )}
                                                                </td>
                                                                <td>
                                                                    {lead.aluno ??
                                                                        '—'}
                                                                </td>
                                                                <td>
                                                                    {
                                                                        lead.responsavel
                                                                    }
                                                                </td>
                                                                <td>
                                                                    {lead.telefone ??
                                                                        '—'}
                                                                </td>
                                                                <td>
                                                                    {
                                                                        lead.status
                                                                    }
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                        <nav
                                            aria-label="Paginação dos leads em acompanhamento"
                                            className="es-seller-pagination"
                                        >
                                            <button
                                                className="es-seller-page-button"
                                                disabled={
                                                    followUpPage === 1 ||
                                                    sellerDetail.follow_up_leads
                                                        .length <= pageSize
                                                }
                                                onClick={() =>
                                                    setFollowUpPage(
                                                        followUpPage - 1,
                                                    )
                                                }
                                                type="button"
                                            >
                                                Anterior
                                            </button>
                                            <span aria-live="polite">
                                                Página {followUpPage} de{' '}
                                                {followUpPageCount} ·{' '}
                                                {
                                                    sellerDetail.follow_up_leads
                                                        .length
                                                }{' '}
                                                leads
                                            </span>
                                            <button
                                                className="es-seller-page-button"
                                                disabled={
                                                    sellerDetail.follow_up_leads
                                                        .length <= pageSize ||
                                                    followUpPage >=
                                                        followUpPageCount
                                                }
                                                onClick={() =>
                                                    setFollowUpPage(
                                                        followUpPage + 1,
                                                    )
                                                }
                                                type="button"
                                            >
                                                Próxima
                                            </button>
                                        </nav>
                                    </>
                                ) : (
                                    <p className="es-empty">
                                        Nenhum lead em acompanhamento nesta
                                        data.
                                    </p>
                                )}
                            </section>
                            <section>
                                <h3>Matrículas realizadas</h3>
                                {sellerDetail.enrollments.length > 0 ? (
                                    <>
                                        <div className="es-table-wrap">
                                            <table className="es-table">
                                                <thead>
                                                    <tr>
                                                        <th>Data</th>
                                                        <th>Aluno</th>
                                                        <th>Responsável</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {visibleEnrollments.map(
                                                        (enrollment) => (
                                                            <tr
                                                                aria-label={
                                                                    enrollment.lead_id
                                                                        ? `Detalhes do lead de ${enrollment.responsavel ?? enrollment.aluno ?? 'matrícula'}`
                                                                        : undefined
                                                                }
                                                                key={
                                                                    enrollment.id
                                                                }
                                                                onClick={
                                                                    enrollment.lead_id
                                                                        ? () =>
                                                                              openLeadDetails(
                                                                                  enrollment.lead_id,
                                                                              )
                                                                        : undefined
                                                                }
                                                                onKeyDown={(
                                                                    event,
                                                                ) => {
                                                                    if (
                                                                        enrollment.lead_id &&
                                                                        (event.key ===
                                                                            'Enter' ||
                                                                            event.key ===
                                                                                ' ')
                                                                    ) {
                                                                        event.preventDefault();
                                                                        openLeadDetails(
                                                                            enrollment.lead_id,
                                                                        );
                                                                    }
                                                                }}
                                                                role={
                                                                    enrollment.lead_id
                                                                        ? 'link'
                                                                        : undefined
                                                                }
                                                                tabIndex={
                                                                    enrollment.lead_id
                                                                        ? 0
                                                                        : undefined
                                                                }
                                                            >
                                                                <td>
                                                                    {formatDate(
                                                                        enrollment.data,
                                                                    )}
                                                                </td>
                                                                <td>
                                                                    {enrollment.aluno ??
                                                                        '—'}
                                                                </td>
                                                                <td>
                                                                    {enrollment.responsavel ??
                                                                        '—'}
                                                                </td>
                                                                <td>
                                                                    {
                                                                        enrollment.status
                                                                    }
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                        <nav
                                            aria-label="Paginação das matrículas"
                                            className="es-seller-pagination"
                                        >
                                            <button
                                                className="es-seller-page-button"
                                                disabled={
                                                    enrollmentPage === 1 ||
                                                    sellerDetail.enrollments
                                                        .length <= pageSize
                                                }
                                                onClick={() =>
                                                    setEnrollmentPage(
                                                        enrollmentPage - 1,
                                                    )
                                                }
                                                type="button"
                                            >
                                                Anterior
                                            </button>
                                            <span aria-live="polite">
                                                Página {enrollmentPage} de{' '}
                                                {enrollmentPageCount} ·{' '}
                                                {
                                                    sellerDetail.enrollments
                                                        .length
                                                }{' '}
                                                matrículas
                                            </span>
                                            <button
                                                className="es-seller-page-button"
                                                disabled={
                                                    sellerDetail.enrollments
                                                        .length <= pageSize ||
                                                    enrollmentPage >=
                                                        enrollmentPageCount
                                                }
                                                onClick={() =>
                                                    setEnrollmentPage(
                                                        enrollmentPage + 1,
                                                    )
                                                }
                                                type="button"
                                            >
                                                Próxima
                                            </button>
                                        </nav>
                                    </>
                                ) : (
                                    <p className="es-empty">
                                        Nenhuma matrícula registrada para este
                                        vendedor.
                                    </p>
                                )}
                            </section>
                        </div>
                    </section>
                </div>
            )}
            {selectedLead && sellerDetail && (
                <div
                    className="es-modal es-lead-detail-overlay"
                    role="presentation"
                >
                    <button
                        aria-label="Fechar detalhamento da lead"
                        className="es-modal-backdrop"
                        onClick={closeLeadDetails}
                        tabIndex={-1}
                        type="button"
                    />
                    <section
                        aria-labelledby="seller-lead-detail-title"
                        aria-modal="true"
                        className="es-modal-card es-seller-lead-modal"
                        role="dialog"
                    >
                        <div className="es-modal-head">
                            <h2 id="seller-lead-detail-title">
                                Lead: {selectedLead.responsavel}
                            </h2>
                            <button
                                aria-label="Fechar detalhamento da lead"
                                className="es-close"
                                onClick={closeLeadDetails}
                                type="button"
                            >
                                ×
                            </button>
                        </div>
                        <div className="es-seller-lead-content">
                            <div
                                aria-label="Seções dos detalhes da lead"
                                className="es-lead-tabs"
                                role="tablist"
                            >
                                <button
                                    aria-controls="seller-lead-tabpanel-dados"
                                    aria-selected={leadDetailTab === 'dados'}
                                    className={
                                        leadDetailTab === 'dados'
                                            ? 'active'
                                            : ''
                                    }
                                    id="seller-lead-tab-dados"
                                    onClick={() => setLeadDetailTab('dados')}
                                    role="tab"
                                    type="button"
                                >
                                    Dados da lead
                                </button>
                                <button
                                    aria-controls="seller-lead-tabpanel-atendimentos"
                                    aria-selected={
                                        leadDetailTab === 'atendimentos'
                                    }
                                    className={
                                        leadDetailTab === 'atendimentos'
                                            ? 'active'
                                            : ''
                                    }
                                    id="seller-lead-tab-atendimentos"
                                    onClick={() =>
                                        setLeadDetailTab('atendimentos')
                                    }
                                    role="tab"
                                    type="button"
                                >
                                    Atendimentos
                                </button>
                                <button
                                    aria-controls="seller-lead-tabpanel-historico"
                                    aria-selected={
                                        leadDetailTab === 'historico'
                                    }
                                    className={
                                        leadDetailTab === 'historico'
                                            ? 'active'
                                            : ''
                                    }
                                    id="seller-lead-tab-historico"
                                    onClick={() =>
                                        setLeadDetailTab('historico')
                                    }
                                    role="tab"
                                    type="button"
                                >
                                    Histórico
                                </button>
                            </div>
                            {leadDetailTab === 'dados' && (
                                <section
                                    aria-labelledby="seller-lead-tab-dados"
                                    className="es-lead-detail"
                                    id="seller-lead-tabpanel-dados"
                                    role="tabpanel"
                                >
                                    <h3>Dados da lead</h3>
                                    <dl>
                                        <div>
                                            <dt>Aluno</dt>
                                            <dd>{selectedLead.aluno ?? '—'}</dd>
                                        </div>
                                        <div>
                                            <dt>Unidade</dt>
                                            <dd>
                                                {selectedLead.unidade?.nome ??
                                                    '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Situação atual</dt>
                                            <dd>
                                                {selectedLead.status?.nome ??
                                                    '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Data de entrada</dt>
                                            <dd>
                                                {formatDate(selectedLead.data)}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Vendedor</dt>
                                            <dd>
                                                {selectedLead.vendedor?.nome ??
                                                    '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Origem</dt>
                                            <dd>
                                                {selectedLead.origem || '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Telefone</dt>
                                            <dd>
                                                {selectedLead.telefone || '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Telefone secundário</dt>
                                            <dd>
                                                {selectedLead.telefone_secundario ||
                                                    '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>E-mail</dt>
                                            <dd>{selectedLead.email || '—'}</dd>
                                        </div>
                                        <div>
                                            <dt>Série / ciclo / turno</dt>
                                            <dd>
                                                {[
                                                    selectedLead.serie?.nome,
                                                    selectedLead.ciclo?.nome,
                                                    selectedLead.turno?.nome,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' / ') || '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Data de nascimento</dt>
                                            <dd>
                                                {formatDate(
                                                    selectedLead.nascimento,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Idade</dt>
                                            <dd>{selectedLead.idade ?? '—'}</dd>
                                        </div>
                                        <div>
                                            <dt>Canal de marketing</dt>
                                            <dd>
                                                {selectedLead.canal_mkt || '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Canal de contato</dt>
                                            <dd>
                                                {selectedLead.canal_contato ||
                                                    '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Data de retorno</dt>
                                            <dd>
                                                {formatDate(
                                                    selectedLead.retorno_data,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Data de matrícula</dt>
                                            <dd>
                                                {formatDate(
                                                    selectedLead.data_matricula,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Desconto</dt>
                                            <dd>
                                                {selectedLead.desconto === null
                                                    ? '—'
                                                    : `${selectedLead.desconto}%`}
                                            </dd>
                                        </div>
                                        <div className="es-lead-detail-wide">
                                            <dt>Observações</dt>
                                            <dd>
                                                {selectedLead.observacoes ||
                                                    '—'}
                                            </dd>
                                        </div>
                                    </dl>
                                </section>
                            )}
                            {leadDetailTab === 'atendimentos' && (
                                <div
                                    aria-labelledby="seller-lead-tab-atendimentos"
                                    id="seller-lead-tabpanel-atendimentos"
                                    role="tabpanel"
                                >
                                    <section>
                                        <h3>Agendamentos</h3>
                                        {selectedLead.agendamentos.length ? (
                                            <ul>
                                                {selectedLead.agendamentos.map(
                                                    (appointment) => (
                                                        <li
                                                            key={appointment.id}
                                                        >
                                                            {formatDate(
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
                                            <p>
                                                Nenhum agendamento registrado.
                                            </p>
                                        )}
                                    </section>
                                    <section>
                                        <h3>Atendimentos e visitas</h3>
                                        {selectedLead.visitas.length ? (
                                            <ul>
                                                {selectedLead.visitas.map(
                                                    (visit) => (
                                                        <li key={visit.id}>
                                                            {formatDate(
                                                                visit.data,
                                                            )}
                                                            {visit.horario
                                                                ? ` às ${visit.horario.slice(0, 5)}`
                                                                : ''}
                                                            {' · '}
                                                            {
                                                                visit.status
                                                            } /{' '}
                                                            {visit.situacao}
                                                            {' · '}
                                                            {visit.vendedor
                                                                ?.nome ??
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
                            {leadDetailTab === 'historico' && (
                                <div
                                    aria-labelledby="seller-lead-tab-historico"
                                    id="seller-lead-tabpanel-historico"
                                    role="tabpanel"
                                >
                                    <section>
                                        <h3>Histórico de alterações</h3>
                                        {selectedLead.edit_histories.length ? (
                                            <ol>
                                                {selectedLead.edit_histories.map(
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
                                                            {history.user
                                                                ?.name ??
                                                                'Usuário removido'}
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
                                        {selectedLead.status_histories
                                            .length ? (
                                            <ol>
                                                {selectedLead.status_histories.map(
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
                                                                ?.nome ??
                                                                'Início'}
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
                        </div>
                    </section>
                </div>
            )}
            <p className="es-report-note">
                O funil é reconstruído a partir do histórico de mudanças até o
                fim do dia selecionado. Os cartões e indicadores de desempenho
                continuam acumulados até hoje; apenas a distribuição por status
                usa a data escolhida. É possível consultar desde{' '}
                {historyStartDate}; mudanças anteriores à implantação não podem
                ser recuperadas. Matrículas comerciais incluem registros
                históricos sem turma.
            </p>
        </VisitLayout>
    );
}
