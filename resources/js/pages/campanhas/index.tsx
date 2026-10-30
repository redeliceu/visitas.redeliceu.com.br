import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/visitas/RecordModal';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type SellerGoal = {
    id: number;
    nome: string;
    meta: number | null;
    sales: number;
};
type Campaign = {
    id: number;
    unidade_id: number;
    inicio: string | null;
    fim: string | null;
    meta: number | null;
};
type CampaignUnit = {
    id: number;
    nome: string;
    campaign: Campaign | null;
    sellers: SellerGoal[];
    summary: {
        leads: number;
        appointments: number;
        visits: number;
        sales: number;
    };
};
type Unit = { id: number; nome: string };
type Props = {
    auth: Auth;
    units: CampaignUnit[];
    availableUnits: Unit[];
    selectedUnitId: number | null;
};
type CampaignData = {
    unidade_id: string;
    inicio: string;
    fim: string;
    meta: string;
};
type GoalData = { vendedor_id: string; meta: string };

function CampaignEditor({ unit }: { unit: CampaignUnit }) {
    const form = useForm<CampaignData>({
        unidade_id: String(unit.id),
        inicio: unit.campaign?.inicio ?? '',
        fim: unit.campaign?.fim ?? '',
        meta:
            unit.campaign?.meta === null || unit.campaign?.meta === undefined
                ? '100'
                : String(unit.campaign.meta),
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (unit.campaign) {
            form.put(`/campanhas/${unit.campaign.id}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/campanhas', { preserveScroll: true });
        }
    }

    return (
        <form className="es-panel es-campaign-form" onSubmit={submit}>
            <h3>{unit.campaign ? 'Editar campanha' : 'Definir campanha'}</h3>
            <p className="es-sub">{unit.nome}</p>
            <input
                name="unidade_id"
                type="hidden"
                value={form.data.unidade_id}
            />
            <div className="es-campaign-fields">
                <Field error={form.errors.inicio} label="Início">
                    <input
                        onChange={(event) =>
                            form.setData('inicio', event.target.value)
                        }
                        required
                        type="date"
                        value={form.data.inicio}
                    />
                </Field>
                <Field error={form.errors.fim} label="Fim">
                    <input
                        onChange={(event) =>
                            form.setData('fim', event.target.value)
                        }
                        required
                        type="date"
                        value={form.data.fim}
                    />
                </Field>
                <Field error={form.errors.meta} label="Meta de matrículas">
                    <input
                        min="1"
                        max="100000"
                        onChange={(event) =>
                            form.setData('meta', event.target.value)
                        }
                        required
                        type="number"
                        value={form.data.meta}
                    />
                </Field>
            </div>
            {form.errors.unidade_id && (
                <div className="field-error" role="alert">
                    {form.errors.unidade_id}
                </div>
            )}
            <button
                className="btn btn-primary"
                disabled={form.processing}
                type="submit"
            >
                {form.processing ? 'Salvando...' : 'Salvar campanha'}
            </button>
        </form>
    );
}

function SellerGoalEditor({
    campaign,
    seller,
}: {
    campaign: Campaign;
    seller: SellerGoal;
}) {
    const form = useForm<GoalData>({
        vendedor_id: String(seller.id),
        meta: String(seller.meta ?? 0),
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/campanhas/${campaign.id}/metas`, {
            preserveScroll: true,
        });
    }

    return (
        <form className="es-goal-row" onSubmit={submit}>
            <strong>{seller.nome}</strong>
            <span>{seller.sales} matrículas</span>
            <label>
                Meta individual
                <input
                    max="100000"
                    min="0"
                    onChange={(event) =>
                        form.setData('meta', event.target.value)
                    }
                    required
                    type="number"
                    value={form.data.meta}
                />
            </label>
            <button
                className="btn btn-secondary"
                disabled={form.processing}
                type="submit"
            >
                Salvar
            </button>
            {form.errors.meta && (
                <span className="field-error">{form.errors.meta}</span>
            )}
        </form>
    );
}

export default function Campaigns({
    auth,
    units,
    availableUnits,
    selectedUnitId,
}: Props) {
    const canManage =
        auth.user?.role === 'master' || auth.user?.role === 'gestor';

    return (
        <VisitLayout
            active="campanhas"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Metas"
            units={availableUnits}
        >
            <div className="es-header">
                <div>
                    <h1>Metas e Campanhas</h1>
                    <div className="es-sub">
                        Períodos, metas comerciais e acompanhamento por vendedor
                    </div>
                </div>
            </div>

            {units.map((unit) => {
                const campaign = unit.campaign;
                const progress =
                    campaign?.meta && campaign.meta > 0
                        ? Math.min(
                              100,
                              (unit.summary.sales / campaign.meta) * 100,
                          )
                        : 0;

                return (
                    <section className="es-campaign-unit" key={unit.id}>
                        {campaign && (
                            <>
                                <div className="es-unit-head">
                                    <h2>{unit.nome}</h2>
                                    <span>
                                        {campaign.inicio} até {campaign.fim}
                                    </span>
                                </div>
                                <div className="es-panel es-campaign-pad">
                                    <div className="es-grid">
                                        <div className="es-stat">
                                            <small>Meta de matrículas</small>
                                            <strong>{campaign.meta}</strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Matrículas realizadas</small>
                                            <strong>
                                                {unit.summary.sales}
                                            </strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Meta atingida</small>
                                            <strong>
                                                {progress
                                                    .toFixed(1)
                                                    .replace('.', ',')}
                                                %
                                            </strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Faltam</small>
                                            <strong>
                                                {Math.max(
                                                    0,
                                                    (campaign.meta ?? 0) -
                                                        unit.summary.sales,
                                                )}
                                            </strong>
                                        </div>
                                    </div>
                                    <div
                                        aria-label={`Meta atingida: ${progress.toFixed(1)}%`}
                                        className="es-bar es-campaign-bar"
                                        role="img"
                                    >
                                        <span
                                            style={{ width: `${progress}%` }}
                                        />
                                    </div>
                                    <h3>Funil da campanha</h3>
                                    <div className="es-grid">
                                        <div className="es-stat">
                                            <small>Leads</small>
                                            <strong>
                                                {unit.summary.leads}
                                            </strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Agendamentos</small>
                                            <strong>
                                                {unit.summary.appointments}
                                            </strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Visitas</small>
                                            <strong>
                                                {unit.summary.visits}
                                            </strong>
                                        </div>
                                        <div className="es-stat">
                                            <small>Matrículas</small>
                                            <strong>
                                                {unit.summary.sales}
                                            </strong>
                                        </div>
                                    </div>
                                    <p className="es-sub">
                                        Volumes por etapa dentro do período; não
                                        representam a conversão de uma mesma
                                        coorte.
                                    </p>
                                    <h3>Metas individuais</h3>
                                    {unit.sellers.length > 0 && canManage ? (
                                        <div className="es-goal-list">
                                            {unit.sellers.map((seller) => (
                                                <SellerGoalEditor
                                                    campaign={campaign}
                                                    key={seller.id}
                                                    seller={seller}
                                                />
                                            ))}
                                        </div>
                                    ) : unit.sellers.length > 0 ? (
                                        <div className="es-goal-list">
                                            {unit.sellers.map((seller) => (
                                                <div
                                                    className="es-goal-row"
                                                    key={seller.id}
                                                >
                                                    <strong>
                                                        {seller.nome}
                                                    </strong>
                                                    <span>
                                                        {seller.sales}{' '}
                                                        matrículas
                                                    </span>
                                                    <span>
                                                        Meta:{' '}
                                                        {seller.meta ??
                                                            'não definida'}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="es-sub">
                                            Não há vendedores ativos vinculados
                                            a esta unidade.
                                        </p>
                                    )}
                                </div>
                            </>
                        )}
                        {canManage ? (
                            <CampaignEditor unit={unit} />
                        ) : (
                            !campaign && (
                                <p className="es-sub es-campaign-form">
                                    Nenhuma campanha configurada para esta
                                    unidade.
                                </p>
                            )
                        )}
                    </section>
                );
            })}
            {units.length === 0 && (
                <div className="es-empty">
                    Nenhuma unidade disponível para configurar metas.
                </div>
            )}
        </VisitLayout>
    );
}
