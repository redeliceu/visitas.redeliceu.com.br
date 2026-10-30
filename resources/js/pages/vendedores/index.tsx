import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Status = { id: number; nome: string };
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
};
type Props = {
    auth: Auth;
    units: Unit[];
    selectedUnitId: number | null;
    statuses: Status[];
    sellers: Seller[];
};

export default function Sellers({
    auth,
    units,
    selectedUnitId,
    statuses,
    sellers,
}: Props) {
    return (
        <VisitLayout
            active="vendedores"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Vendedores"
            units={units}
        >
            <div className="es-header">
                <div>
                    <h1>Vendedores</h1>
                    <div className="es-sub">
                        Equipe comercial e leads distribuídas por etapa atual do
                        funil
                    </div>
                </div>
                <a className="btn btn-primary" href="/painel-vendas">
                    <i className="fas fa-chart-pie" aria-hidden="true" /> Painel
                    de Vendas
                </a>
            </div>
            <section className="es-panel es-table-wrap">
                <table className="es-table es-sellers-table">
                    <thead>
                        <tr>
                            <th>Vendedor</th>
                            <th>Unidade</th>
                            <th>Leads</th>
                            <th>Etapas do funil</th>
                            <th>Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sellers.map((seller) => (
                            <tr key={seller.id}>
                                <td>
                                    <strong>{seller.nome}</strong>
                                    <div className="es-sub">
                                        {seller.user_name
                                            ? `Conta: ${seller.user_name}`
                                            : 'Cadastro sem conta associada'}
                                    </div>
                                </td>
                                <td>{seller.unidade}</td>
                                <td>{seller.leads}</td>
                                <td>
                                    <div className="es-seller-stage-list">
                                        {statuses.map((status) => (
                                            <span
                                                className="es-seller-stage"
                                                key={status.id}
                                            >
                                                {status.nome}:{' '}
                                                {seller.status_counts[
                                                    status.id
                                                ] ?? 0}
                                            </span>
                                        ))}
                                    </div>
                                </td>
                                <td>
                                    <span
                                        className={`es-pill ${seller.ativo ? 'green' : 'red'}`}
                                    >
                                        {seller.ativo ? 'Ativo' : 'Inativo'}
                                    </span>
                                </td>
                            </tr>
                        ))}
                        {sellers.length === 0 && (
                            <tr>
                                <td className="es-empty" colSpan={5}>
                                    Nenhum vendedor disponível para este filtro.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </section>
            <p className="es-report-note">
                Usuários com perfil Atendimento / Vendedor são incluídos
                automaticamente nesta lista para cada unidade atribuída à conta.
                O painel mostra apenas registros das unidades autorizadas.
            </p>
        </VisitLayout>
    );
}
