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
type Campaign = {
    id: number;
    unidade_id: number;
    unidade: Unit;
    inicio: string | null;
    fim: string | null;
    meta: number | null;
    sales: number;
    leads: number;
    appointments: number;
    visits: number;
};
type UnitRow = Metrics & { unit_id: number };
type Props = {
    auth: Auth;
    units: Unit[];
    selectedUnitId: number | null;
    metrics: Metrics;
    unitRows: UnitRow[];
    classSummary: { classes: number; capacity: number; occupied: number };
    campaigns: Campaign[];
};

const roleLabels = {
    master: 'Gestão geral',
    gestor: 'Gestor de unidade',
    atendimento: 'Atendimento / Vendedor',
};

export default function Home({
    auth,
    units,
    selectedUnitId,
    metrics,
    unitRows,
    classSummary,
    campaigns,
}: Props) {
    const user = auth.user;

    if (!user) {
        return null;
    }

    return (
        <VisitLayout
            active="home"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Dashboard"
            units={units}
        >
            <div className="es-header">
                <div>
                    <h1>Olá, {user.name}</h1>
                    <div className="es-sub">
                        {roleLabels[user.role]} •{' '}
                        {user.role === 'master'
                            ? 'Acesso geral às unidades'
                            : `Unidades vinculadas: ${user.unidades.map((unit) => unit.nome).join(', ')}`}
                    </div>
                </div>
                <a className="btn btn-secondary" href="/profile">
                    Meu perfil e senha
                </a>
            </div>
            <section className="es-panel">
                <h2>Visão geral</h2>
                <p className="es-sub">
                    Acumulado histórico e ocupação das unidades às quais sua
                    conta tem permissão.
                </p>
                <div className="es-grid es-dashboard-overview">
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
                        <strong>
                            {metrics.visits
                                ? `${((metrics.sales / metrics.visits) * 100).toFixed(1).replace('.', ',')}%`
                                : '0,0%'}
                        </strong>
                    </div>
                    <div className="es-stat">
                        <small>Turmas</small>
                        <strong>{classSummary.classes}</strong>
                    </div>
                    <div className="es-stat">
                        <small>Vagas ocupadas</small>
                        <strong>{classSummary.occupied}</strong>
                    </div>
                    <div className="es-stat">
                        <small>Vagas disponíveis</small>
                        <strong>
                            {Math.max(
                                0,
                                classSummary.capacity - classSummary.occupied,
                            )}
                        </strong>
                    </div>
                </div>
                <h2 className="es-dashboard-section-title">
                    Indicadores por unidade
                </h2>
                <div className="es-table-wrap">
                    <table className="es-table">
                        <thead>
                            <tr>
                                <th>Unidade</th>
                                <th>Leads</th>
                                <th>Agendamentos</th>
                                <th>Visitas</th>
                                <th>Matrículas</th>
                            </tr>
                        </thead>
                        <tbody>
                            {unitRows.map((row) => (
                                <tr key={row.unit_id}>
                                    <td>
                                        {units.find(
                                            (unit) => unit.id === row.unit_id,
                                        )?.nome ?? row.label}
                                    </td>
                                    <td>{row.leads}</td>
                                    <td>{row.appointments}</td>
                                    <td>{row.visits}</td>
                                    <td>{row.sales}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <h2 className="es-dashboard-section-title">
                    Acompanhamento das campanhas
                </h2>
                {campaigns.length ? (
                    <div className="es-dashboard-campaigns">
                        {campaigns.map((campaign) => {
                            const progress =
                                campaign.meta && campaign.meta > 0
                                    ? Math.min(
                                          100,
                                          (campaign.sales / campaign.meta) *
                                              100,
                                      )
                                    : 0;

                            return (
                                <article
                                    className="es-panel es-campaign-pad"
                                    key={campaign.id}
                                >
                                    <div className="es-unit-head">
                                        <h3>{campaign.unidade.nome}</h3>
                                        <span>
                                            {campaign.inicio} até {campaign.fim}
                                        </span>
                                    </div>
                                    <div className="es-grid">
                                        <div className="es-stat">
                                            <small>Meta</small>
                                            <strong>
                                                {campaign.meta ?? '—'}
                                            </strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Matrículas</small>
                                            <strong>{campaign.sales}</strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Percentual atingido</small>
                                            <strong>
                                                {progress
                                                    .toFixed(1)
                                                    .replace('.', ',')}
                                                %
                                            </strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Restantes</small>
                                            <strong>
                                                {Math.max(
                                                    0,
                                                    (campaign.meta ?? 0) -
                                                        campaign.sales,
                                                )}
                                            </strong>
                                        </div>
                                    </div>
                                    <div className="es-bar es-campaign-bar">
                                        <span
                                            style={{
                                                width: `${progress}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="es-sub">
                                        Funil no período: {campaign.leads} leads
                                        → {campaign.appointments} agendamentos →{' '}
                                        {campaign.visits} visitas →{' '}
                                        {campaign.sales} matrículas.
                                    </p>
                                </article>
                            );
                        })}
                    </div>
                ) : (
                    <p className="es-empty">
                        Nenhuma campanha configurada para as unidades
                        selecionadas.
                    </p>
                )}
                <div className="es-toolbar" style={{ marginTop: '18px' }}>
                    <a className="btn btn-primary" href="/leads">
                        <i className="fas fa-address-card" aria-hidden="true" />{' '}
                        Leads
                    </a>
                    <a className="btn btn-secondary" href="/agendamentos">
                        <i
                            className="fas fa-calendar-check"
                            aria-hidden="true"
                        />{' '}
                        Agendamentos
                    </a>
                    <a className="btn btn-secondary" href="/visitas">
                        <i className="fas fa-school" aria-hidden="true" />{' '}
                        Visitas
                    </a>
                    <a className="btn btn-secondary" href="/turmas">
                        <i
                            className="fas fa-table-cells-large"
                            aria-hidden="true"
                        />{' '}
                        Mapa de Turmas
                    </a>
                    <a className="btn btn-secondary" href="/campanhas">
                        <i className="fas fa-bullseye" aria-hidden="true" />{' '}
                        Metas
                    </a>
                    <a className="btn btn-secondary" href="/relatorios">
                        <i className="fas fa-file-csv" aria-hidden="true" />{' '}
                        Relatórios
                    </a>
                    {user.role === 'master' && (
                        <>
                            <a
                                className="btn btn-secondary"
                                href="/admin/users"
                            >
                                Gerenciar usuários
                            </a>
                            <a
                                className="btn btn-secondary"
                                href="/admin/units"
                            >
                                Gerenciar unidades
                            </a>
                        </>
                    )}
                </div>
            </section>
        </VisitLayout>
    );
}
