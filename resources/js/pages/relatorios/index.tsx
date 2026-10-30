import { router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Seller = { id: number; nome: string; unidade: string | null };
type ReportRow = {
    label: string;
    unit_id: number;
    seller_id: number;
    leads: number;
    appointments: number;
    visits: number;
    sales: number;
};
type Report = {
    all: ReportRow;
    byUnit: ReportRow[];
    bySeller: ReportRow[];
};
type Filters = {
    inicio: string;
    fim: string;
    unidade_id: number | null;
    vendedor_id: number | null;
};
type Props = {
    auth: Auth;
    report: Report;
    units: Unit[];
    sellers: Seller[];
    selectedUnitId: number | null;
    filters: Filters;
};

function percentage(value: number, total: number): string {
    return total > 0
        ? `${((value / total) * 100).toFixed(1).replace('.', ',')}%`
        : '0,0%';
}

function ReportTable({ title, rows }: { title: string; rows: ReportRow[] }) {
    return (
        <section className="es-report-section">
            <h2 className="es-report-title">{title}</h2>
            <div className="es-panel es-table-wrap">
                <table className="es-table">
                    <thead>
                        <tr>
                            <th>Unidade / Vendedor</th>
                            <th>Leads</th>
                            <th>Agendamentos</th>
                            <th>Visitas</th>
                            <th>Matrículas</th>
                            <th>Lead → Agend.</th>
                            <th>Agend. → Visita</th>
                            <th>Visita → Matrícula</th>
                            <th>Lead → Matrícula</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={`${row.unit_id}-${row.seller_id}-${row.label}`}
                            >
                                <td>{row.label}</td>
                                <td>{row.leads}</td>
                                <td>{row.appointments}</td>
                                <td>{row.visits}</td>
                                <td>{row.sales}</td>
                                <td>
                                    {percentage(row.appointments, row.leads)}
                                </td>
                                <td>
                                    {percentage(row.visits, row.appointments)}
                                </td>
                                <td>{percentage(row.sales, row.visits)}</td>
                                <td>{percentage(row.sales, row.leads)}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td className="es-empty" colSpan={9}>
                                    Nenhum registro encontrado no período.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export default function Reports({
    auth,
    report,
    units,
    selectedUnitId,
    filters,
    sellers,
}: Props) {
    const [start, setStart] = useState(filters.inicio);
    const [end, setEnd] = useState(filters.fim);
    const [sellerId, setSellerId] = useState(String(filters.vendedor_id ?? ''));
    function queryString(): string {
        const params = new URLSearchParams();
        params.set('inicio', start);
        params.set('fim', end);
        if (selectedUnitId) {
            params.set('unidade_id', String(selectedUnitId));
        }
        if (sellerId) {
            params.set('vendedor_id', sellerId);
        }

        return params.toString();
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(
            '/relatorios',
            {
                inicio: start,
                fim: end,
                unidade_id: selectedUnitId ?? undefined,
                vendedor_id: sellerId || undefined,
            },
            { preserveScroll: true },
        );
    }

    return (
        <VisitLayout
            active="relatorios"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Relatórios"
            units={units}
        >
            <div className="es-header">
                <div>
                    <h1>Relatórios Comerciais</h1>
                    <div className="es-sub">
                        Produção por período, unidade e vendedor
                    </div>
                </div>
                <div className="es-header-actions">
                    <a
                        className="btn btn-secondary"
                        href={`/relatorios/pdf?${queryString()}`}
                    >
                        <i className="fas fa-file-pdf" aria-hidden="true" />{' '}
                        Gerar PDF
                    </a>
                    <a
                        className="btn btn-primary"
                        href={`/relatorios/csv?${queryString()}`}
                    >
                        <i className="fas fa-file-csv" aria-hidden="true" />{' '}
                        Exportar CSV
                    </a>
                </div>
            </div>
            <form className="es-panel es-report-filters" onSubmit={submit}>
                <label>
                    Data inicial
                    <input
                        onChange={(event) => setStart(event.target.value)}
                        required
                        type="date"
                        value={start}
                    />
                </label>
                <label>
                    Data final
                    <input
                        min={start}
                        onChange={(event) => setEnd(event.target.value)}
                        required
                        type="date"
                        value={end}
                    />
                </label>
                <label>
                    Vendedor
                    <select
                        onChange={(event) => setSellerId(event.target.value)}
                        value={sellerId}
                    >
                        <option value="">Todos os vendedores</option>
                        {sellers.map((seller) => (
                            <option key={seller.id} value={seller.id}>
                                {seller.nome} • {seller.unidade}
                            </option>
                        ))}
                    </select>
                </label>
                <button className="btn btn-primary" type="submit">
                    Aplicar filtros
                </button>
            </form>
            <div className="es-grid es-report-stats">
                <div className="es-stat">
                    <small>Leads</small>
                    <strong>{report.all.leads}</strong>
                </div>
                <div className="es-stat">
                    <small>Agendamentos</small>
                    <strong>{report.all.appointments}</strong>
                </div>
                <div className="es-stat">
                    <small>Visitas</small>
                    <strong>{report.all.visits}</strong>
                </div>
                <div className="es-stat">
                    <small>Matrículas</small>
                    <strong>{report.all.sales}</strong>
                </div>
            </div>
            <ReportTable title="Consolidado" rows={[report.all]} />
            <ReportTable title="Por unidade" rows={report.byUnit} />
            <ReportTable title="Por vendedor" rows={report.bySeller} />
            <div className="es-panel es-report-note">
                Os volumes representam eventos datados no período selecionado.
                As taxas relacionam os volumes das etapas, não conversões de uma
                mesma coorte. Matrículas comerciais incluem visitas
                matriculadas, matrículas ativas e registros históricos sem
                visita vinculada; históricos usam a data de matrícula ou, se
                ausente, a data do lead.
            </div>
        </VisitLayout>
    );
}
