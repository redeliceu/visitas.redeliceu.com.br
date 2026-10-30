import { useState } from 'react';

export type FilterableRecord = {
    id: number;
    data: string | null;
    vendedor_id: number | null;
    status: string | { nome: string } | null;
    responsavel: string | null;
    aluno: string | null;
    telefone: string | null;
    canal_mkt?: string | null;
};

type Seller = { id: number; nome: string };
type Props<T extends FilterableRecord> = {
    page: 'leads' | 'agendamentos' | 'visitas';
    rows: T[];
    sellers: Seller[];
    statuses: string[];
    children: (filteredRows: T[]) => React.ReactNode;
};

type Filters = {
    q: string;
    start: string;
    end: string;
    seller: string;
    status: string;
};

function localToday(): string {
    const date = new Date();
    const offset = date.getTimezoneOffset();

    return new Date(date.getTime() - offset * 60_000)
        .toISOString()
        .slice(0, 10);
}

export default function ListFilters<T extends FilterableRecord>({
    page,
    rows,
    sellers,
    statuses,
    children,
}: Props<T>) {
    const today = page === 'agendamentos' ? localToday() : '';
    const [filters, setFilters] = useState<Filters>({
        q: '',
        start: today,
        end: today,
        seller: '',
        status: '',
    });

    const normalizedQuery = filters.q.trim().toLocaleLowerCase('pt-BR');
    const filteredRows = rows.filter((row) => {
        const status =
            typeof row.status === 'string'
                ? row.status
                : (row.status?.nome ?? '');
        const searchable = [
            row.responsavel,
            row.aluno,
            row.telefone,
            status,
            row.canal_mkt,
        ]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase('pt-BR');
        const rowDate = row.data?.slice(0, 10) ?? '';

        return (
            (!normalizedQuery || searchable.includes(normalizedQuery)) &&
            (!filters.start || (rowDate && rowDate >= filters.start)) &&
            (!filters.end || (rowDate && rowDate <= filters.end)) &&
            (!filters.seller ||
                String(row.vendedor_id ?? '') === filters.seller) &&
            (!filters.status || status === filters.status)
        );
    });

    function setFilter(key: keyof Filters, value: string) {
        setFilters((current) => ({ ...current, [key]: value }));
    }

    function clearFilters() {
        setFilters({ q: '', start: '', end: '', seller: '', status: '' });
    }

    function filterToday() {
        const date = localToday();
        setFilters((current) => ({ ...current, start: date, end: date }));
    }

    const totals = filteredRows.reduce<Record<string, number>>(
        (summary, row) => {
            const status =
                (typeof row.status === 'string'
                    ? row.status
                    : row.status?.nome) || 'Sem status';
            summary[status] = (summary[status] ?? 0) + 1;

            return summary;
        },
        {},
    );

    return (
        <>
            <section className="es-panel es-list-filters">
                <label className="es-search-row">
                    <i className="fas fa-search" aria-hidden="true" />
                    <input
                        aria-label="Pesquisar"
                        onChange={(event) => setFilter('q', event.target.value)}
                        placeholder="Buscar responsável, aluno ou telefone..."
                        value={filters.q}
                    />
                </label>
                <div className="es-filter-grid">
                    <label>
                        Data inicial
                        <input
                            onChange={(event) =>
                                setFilter('start', event.target.value)
                            }
                            type="date"
                            value={filters.start}
                        />
                    </label>
                    <label>
                        Data final
                        <input
                            onChange={(event) =>
                                setFilter('end', event.target.value)
                            }
                            type="date"
                            value={filters.end}
                        />
                    </label>
                    <label>
                        Vendedor
                        <select
                            onChange={(event) =>
                                setFilter('seller', event.target.value)
                            }
                            value={filters.seller}
                        >
                            <option value="">Todos os vendedores</option>
                            {sellers.map((seller) => (
                                <option key={seller.id} value={seller.id}>
                                    {seller.nome}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Situação
                        <select
                            onChange={(event) =>
                                setFilter('status', event.target.value)
                            }
                            value={filters.status}
                        >
                            <option value="">Todas as situações</option>
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button
                        className="btn btn-primary es-today"
                        onClick={filterToday}
                        type="button"
                    >
                        <i className="fas fa-calendar-day" aria-hidden="true" />{' '}
                        Hoje
                    </button>
                    <button
                        className="btn btn-secondary es-clear"
                        onClick={clearFilters}
                        type="button"
                    >
                        Limpar
                    </button>
                </div>
                <div className="es-filter-foot">
                    <span>
                        {filteredRows.length}{' '}
                        {page === 'leads'
                            ? 'lead(s)'
                            : page === 'visitas'
                              ? 'visita(s)'
                              : 'agendamento(s)'}
                    </span>
                    <span>Filtre por hoje ou escolha um período</span>
                </div>
                <div className="es-status-summary" aria-live="polite">
                    {Object.entries(totals).map(([status, total]) => (
                        <span className="es-summary-chip" key={status}>
                            <span className="es-summary-label">{status}</span>
                            <strong>{total}</strong>
                        </span>
                    ))}
                    {filteredRows.length === 0 && (
                        <span className="es-summary-muted">
                            Nenhum registro no período selecionado.
                        </span>
                    )}
                </div>
            </section>
            {children(filteredRows)}
        </>
    );
}
