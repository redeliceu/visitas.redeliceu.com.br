import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
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
    statuses: Status[];
    series: Array<{ id: number; nome: string }>;
    cycles: Array<{ id: number; nome: string }>;
    shifts: Array<{ id: number; nome: string }>;
};

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
    statuses,
    series,
    cycles,
    shifts,
}: Props) {
    const [editing, setEditing] = useState<Lead | null>(null);
    const [leadModalOpen, setLeadModalOpen] = useState(false);
    const [detailsLead, setDetailsLead] = useState<Lead | null>(null);
    const [scheduling, setScheduling] = useState<Lead | null>(null);
    const leadForm = useForm<LeadData>(emptyLead);
    const scheduleForm = useForm({ data: '', horario: '' });
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

    function openLead(lead: Lead | null = null) {
        setEditing(lead);
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
        const options = { onSuccess: closeLead };

        if (editing) {
            leadForm.put(`/leads/${editing.id}`, options);
        } else {
            leadForm.post('/leads', options);
        }
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
                        Acompanhamento de leads em todas as etapas • todas as
                        unidades
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
                rows={leads}
                sellers={sellers}
                statuses={statuses.map((status) => status.nome)}
            >
                {(filteredLeads) => (
                    <div id="leads-results">
                        <div className="es-panel es-table-wrap">
                            <table className="es-table">
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
                                        <tr key={lead.id}>
                                            <td>
                                                {lead.data?.slice(0, 10) || '—'}
                                            </td>
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
                                                        setDetailsLead(lead)
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
                                                {lead.vendedor?.nome || '—'}
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
                                            <td>
                                                <div className="es-lead-actions">
                                                    <button
                                                        className="es-lead-action"
                                                        onClick={() =>
                                                            setDetailsLead(lead)
                                                        }
                                                        title="Ver dados e histórico da lead"
                                                        type="button"
                                                    >
                                                        <i
                                                            className="fas fa-clock-rotate-left"
                                                            aria-hidden="true"
                                                        />
                                                        <span>Detalhes</span>
                                                    </button>
                                                    <button
                                                        aria-label="Editar lead"
                                                        className="es-lead-action"
                                                        onClick={() =>
                                                            openLead(lead)
                                                        }
                                                        title="Editar lead"
                                                        type="button"
                                                    >
                                                        <i
                                                            className="fas fa-pen"
                                                            aria-hidden="true"
                                                        />
                                                        <span>Editar</span>
                                                    </button>
                                                    {lead.agendamento ? (
                                                        <a
                                                            className="es-lead-action"
                                                            href="/agendamentos"
                                                            title="Abrir agendamentos"
                                                        >
                                                            <i
                                                                className="fas fa-calendar-check"
                                                                aria-hidden="true"
                                                            />
                                                            <span>
                                                                Agendamentos
                                                            </span>
                                                        </a>
                                                    ) : (
                                                        <button
                                                            aria-label="Agendar lead"
                                                            className="es-lead-action es-lead-action-primary"
                                                            onClick={() =>
                                                                openSchedule(
                                                                    lead,
                                                                )
                                                            }
                                                            title="Agendar lead"
                                                            type="button"
                                                        >
                                                            <i
                                                                className="fas fa-calendar-plus"
                                                                aria-hidden="true"
                                                            />
                                                            <span>Agendar</span>
                                                        </button>
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
                    title={`Lead — ${detailsLead.responsavel ?? 'Sem responsável'}`}
                >
                    <div className="es-lead-detail">
                        <section>
                            <h3>Dados da lead</h3>
                            <dl>
                                <div>
                                    <dt>Aluno</dt>
                                    <dd>{detailsLead.aluno || '—'}</dd>
                                </div>
                                <div>
                                    <dt>Unidade</dt>
                                    <dd>{detailsLead.unidade?.nome || '—'}</dd>
                                </div>
                                <div>
                                    <dt>Situação atual</dt>
                                    <dd>{detailsLead.status?.nome || '—'}</dd>
                                </div>
                                <div>
                                    <dt>Data de entrada</dt>
                                    <dd>{dateLabel(detailsLead.data)}</dd>
                                </div>
                                <div>
                                    <dt>Vendedor</dt>
                                    <dd>{detailsLead.vendedor?.nome || '—'}</dd>
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
                                        {detailsLead.telefone_secundario || '—'}
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
                                    <dd>{dateLabel(detailsLead.nascimento)}</dd>
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
                                    <dd>{detailsLead.canal_contato || '—'}</dd>
                                </div>
                                <div>
                                    <dt>Data de retorno</dt>
                                    <dd>
                                        {dateLabel(detailsLead.retorno_data)}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Data de matrícula</dt>
                                    <dd>
                                        {dateLabel(detailsLead.data_matricula)}
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
                                    <dd>{detailsLead.observacoes || '—'}</dd>
                                </div>
                            </dl>
                        </section>

                        <section>
                            <h3>Agendamentos</h3>
                            {detailsLead.agendamento ? (
                                <ul>
                                    <li>
                                        {dateLabel(
                                            detailsLead.agendamento.data,
                                        )}{' '}
                                        às{' '}
                                        {detailsLead.agendamento.horario?.slice(
                                            0,
                                            5,
                                        ) || '—'}
                                        {' — '}
                                        {detailsLead.agendamento.status}
                                        {detailsLead.agendamento.observacoes
                                            ? ` — ${detailsLead.agendamento.observacoes}`
                                            : ''}
                                    </li>
                                </ul>
                            ) : (
                                <p>Nenhum agendamento registrado.</p>
                            )}
                        </section>

                        <section>
                            <h3>Atendimentos e visitas</h3>
                            {detailsLead.visitas.length > 0 ? (
                                <ul>
                                    {detailsLead.visitas.map((visit) => (
                                        <li key={visit.id}>
                                            {dateLabel(visit.data)}
                                            {visit.horario
                                                ? ` às ${visit.horario.slice(0, 5)}`
                                                : ''}
                                            {' — '}
                                            {visit.status} / {visit.situacao}
                                            {' — '}
                                            {visit.vendedor?.nome ||
                                                'Sem vendedor'}
                                            {visit.observacoes
                                                ? ` — ${visit.observacoes}`
                                                : ''}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p>Nenhum atendimento ou visita registrado.</p>
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
                                                    ).toLocaleString('pt-BR')}
                                                </strong>
                                                {' — '}
                                                {history.user?.name ||
                                                    'Usuário removido'}
                                                <ul>
                                                    {history.changes.map(
                                                        (change, index) => (
                                                            <li
                                                                key={`${history.id}-${index}`}
                                                            >
                                                                {change.field}:{' '}
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
                                                    ).toLocaleString('pt-BR')}
                                                </strong>
                                                {' — '}
                                                {history.from_status?.nome ??
                                                    'Início'}
                                                {' → '}
                                                {history.to_status?.nome ??
                                                    'Sem situação'}
                                                {' — '}
                                                {history.changed_by?.name ??
                                                    'Sistema/API ou registro inicial'}
                                            </li>
                                        ),
                                    )}
                                </ol>
                            ) : (
                                <p>Nenhuma alteração de situação registrada.</p>
                            )}
                        </section>

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
                    <Field error={leadForm.errors.unidade_id} label="Unidade">
                        <select
                            onChange={(event) => {
                                const nextUnitId = event.target.value;
                                const ownSeller = sellers.find(
                                    (seller) =>
                                        seller.is_current_user &&
                                        String(seller.unidade_id) ===
                                            nextUnitId,
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
                                <option key={unit.id} value={unit.id}>
                                    {unit.nome}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field error={leadForm.errors.vendedor_id} label="Vendedor">
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
                                        String(seller.unidade_id) ===
                                        leadForm.data.unidade_id,
                                )
                                .map((seller) => (
                                    <option key={seller.id} value={seller.id}>
                                        {seller.nome}
                                    </option>
                                ))}
                        </select>
                    </Field>
                    <Field error={leadForm.errors.data} label="Data do contato">
                        <input
                            onChange={(event) =>
                                leadForm.setData('data', event.target.value)
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
                    <Field error={leadForm.errors.telefone} label="Telefone">
                        <input
                            onChange={(event) =>
                                leadForm.setData('telefone', event.target.value)
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
                            value={leadForm.data.telefone_secundario}
                        />
                    </Field>
                    <Field error={leadForm.errors.email} label="E-mail">
                        <input
                            onChange={(event) =>
                                leadForm.setData('email', event.target.value)
                            }
                            type="email"
                            value={leadForm.data.email}
                        />
                    </Field>
                    <Field error={leadForm.errors.aluno} label="Aluno">
                        <input
                            onChange={(event) =>
                                leadForm.setData('aluno', event.target.value)
                            }
                            value={leadForm.data.aluno}
                        />
                    </Field>
                    <Field error={leadForm.errors.idade} label="Idade">
                        <input
                            min="0"
                            max="120"
                            onChange={(event) =>
                                leadForm.setData('idade', event.target.value)
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
                    <Field error={leadForm.errors.serie_id} label="Série">
                        <select
                            onChange={(event) =>
                                leadForm.setData('serie_id', event.target.value)
                            }
                            value={leadForm.data.serie_id}
                        >
                            <option value="">Selecione</option>
                            {series.map((serie) => (
                                <option key={serie.id} value={serie.id}>
                                    {serie.nome}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field error={leadForm.errors.ciclo_id} label="Ciclo">
                        <select
                            onChange={(event) =>
                                leadForm.setData('ciclo_id', event.target.value)
                            }
                            value={leadForm.data.ciclo_id}
                        >
                            <option value="">Selecione</option>
                            {cycles.map((cycle) => (
                                <option key={cycle.id} value={cycle.id}>
                                    {cycle.nome}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field error={leadForm.errors.turno_id} label="Turno">
                        <select
                            onChange={(event) =>
                                leadForm.setData('turno_id', event.target.value)
                            }
                            value={leadForm.data.turno_id}
                        >
                            <option value="">Selecione</option>
                            {shifts.map((shift) => (
                                <option key={shift.id} value={shift.id}>
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
                    <Field error={leadForm.errors.status_id} label="Status">
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
                                <option key={status.id} value={status.id}>
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
                                leadForm.setData('desconto', event.target.value)
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
                </RecordModal>
            )}
            {scheduling !== null && (
                <RecordModal
                    onClose={closeSchedule}
                    onSubmit={submitSchedule}
                    processing={scheduleForm.processing}
                    submitLabel="Agendar"
                    title={`Agendar visita — ${scheduling.responsavel ?? ''}`}
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
