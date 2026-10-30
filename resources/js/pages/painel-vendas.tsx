import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Status = { id: number; nome: string };
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
    metrics: Omit<Metrics, 'label'>;
};
type Props = {
    auth: Auth;
    units: Unit[];
    selectedUnitId: number | null;
    snapshotDate: string;
    today: string;
    historyStartDate: string;
    metrics: Metrics;
    statuses: Status[];
    sellers: Seller[];
};

function percentage(value: number, total: number): string {
    return total
        ? `${((value / total) * 100).toFixed(1).replace('.', ',')}%`
        : '0,0%';
}

export default function SalesDashboard({
    auth,
    units,
    selectedUnitId,
    snapshotDate,
    today,
    historyStartDate,
    metrics,
    statuses,
    sellers,
}: Props) {
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
                        <h2>Funil individual dos vendedores</h2>
                        <p className="es-sub">
                            Retrato das etapas ao final de {snapshotDate},
                            considerando as mudanças registradas até essa data.
                        </p>
                    </div>
                </div>
                {sellers.map((seller) => (
                    <article className="es-seller-performance" key={seller.id}>
                        <div className="es-seller-performance-heading">
                            <div>
                                <h3>{seller.nome}</h3>
                                <p className="es-sub">{seller.unidade}</p>
                            </div>
                            <span
                                className={`es-pill ${seller.ativo ? 'green' : 'red'}`}
                            >
                                {seller.ativo ? 'Ativo' : 'Inativo'}
                            </span>
                        </div>
                        <div className="es-seller-metrics">
                            <div>
                                <small>Leads</small>
                                <strong>{seller.metrics.leads}</strong>
                            </div>
                            <div>
                                <small>Agendamentos</small>
                                <strong>{seller.metrics.appointments}</strong>
                            </div>
                            <div>
                                <small>Visitas atendidas</small>
                                <strong>{seller.metrics.visits}</strong>
                            </div>
                            <div>
                                <small>Matrículas</small>
                                <strong>{seller.metrics.sales}</strong>
                            </div>
                            <div>
                                <small>Lead → Matrícula</small>
                                <strong>
                                    {percentage(
                                        seller.metrics.sales,
                                        seller.metrics.leads,
                                    )}
                                </strong>
                            </div>
                            <div>
                                <small>Visita → Matrícula</small>
                                <strong>
                                    {percentage(
                                        seller.metrics.sales,
                                        seller.metrics.visits,
                                    )}
                                </strong>
                            </div>
                        </div>
                        <h4>Leads por status na data selecionada</h4>
                        <div className="es-seller-funnel">
                            {statuses.map((status) => (
                                <div
                                    className="es-seller-funnel-stage"
                                    key={status.id}
                                >
                                    <small>{status.nome}</small>
                                    <strong>
                                        {seller.status_counts[status.id] ?? 0}
                                    </strong>
                                </div>
                            ))}
                        </div>
                    </article>
                ))}
                {sellers.length === 0 && (
                    <p className="es-empty">
                        Nenhum vendedor disponível para este filtro.
                    </p>
                )}
            </section>
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
