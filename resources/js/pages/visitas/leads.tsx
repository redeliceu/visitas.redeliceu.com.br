import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Fragment, useState } from 'react';
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
type Lead = FilterableRecord & {
    unidade_id: number;
    unidade?: { id: number; nome: string } | null;
    vendedor?: { id: number; nome: string } | null;
    serie?: { id: number; nome: string } | null;
    agendamento?: { id: number } | null;
    status: { id: number; nome: string } | null;
    status_id: number | null;
    status_histories_count: number;
    status_histories: StatusHistory[];
    idade: number | null;
    serie_id: number | null;
    canal_contato: string | null;
    observacoes: string | null;
};
type LeadData = {
    unidade_id: string;
    vendedor_id: string;
    data: string;
    responsavel: string;
    telefone: string;
    aluno: string;
    idade: string;
    serie_id: string;
    canal_mkt: string;
    canal_contato: string;
    status_id: string;
    observacoes: string;
};
type Props = {
    auth: Auth;
    leads: Lead[];
    units: Unit[];
    selectedUnitId: number | null;
    sellers: Seller[];
    statuses: Status[];
    series: Array<{ id: number; nome: string }>;
};

const emptyLead: LeadData = {
    unidade_id: '',
    vendedor_id: '',
    data: '',
    responsavel: '',
    telefone: '',
    aluno: '',
    idade: '',
    serie_id: '',
    canal_mkt: '',
    canal_contato: '',
    status_id: '',
    observacoes: '',
};

export default function Leads({
    auth,
    leads,
    units,
    selectedUnitId,
    sellers,
    statuses,
    series,
}: Props) {
    const [editing, setEditing] = useState<Lead | null>(null);
    const [leadModalOpen, setLeadModalOpen] = useState(false);
    const [scheduling, setScheduling] = useState<Lead | null>(null);
    const [historyLeadId, setHistoryLeadId] = useState<number | null>(null);
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
                      aluno: lead.aluno ?? '',
                      idade: lead.idade === null ? '' : String(lead.idade),
                      serie_id: lead.serie_id ? String(lead.serie_id) : '',
                      canal_mkt: lead.canal_mkt ?? '',
                      canal_contato: lead.canal_contato ?? '',
                      status_id: lead.status_id
                          ? String(lead.status_id)
                          : String(defaultStatus),
                      observacoes: lead.observacoes ?? '',
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
                                        <Fragment key={lead.id}>
                                            <tr>
                                                <td>
                                                    {lead.data?.slice(0, 10) ||
                                                        '—'}
                                                </td>
                                                {selectedUnitId === null &&
                                                    units.length > 1 && (
                                                        <td>
                                                            {lead.unidade
                                                                ?.nome ?? '—'}
                                                        </td>
                                                    )}
                                                <td>
                                                    <strong>
                                                        {lead.responsavel ||
                                                            '—'}
                                                    </strong>
                                                    <div className="es-sub">
                                                        {lead.aluno}
                                                    </div>
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
                                                            aria-expanded={
                                                                historyLeadId ===
                                                                lead.id
                                                            }
                                                            className="es-lead-action"
                                                            onClick={() =>
                                                                setHistoryLeadId(
                                                                    historyLeadId ===
                                                                        lead.id
                                                                        ? null
                                                                        : lead.id,
                                                                )
                                                            }
                                                            title="Consultar histórico de status"
                                                            type="button"
                                                        >
                                                            <i
                                                                className="fas fa-clock-rotate-left"
                                                                aria-hidden="true"
                                                            />
                                                            <span>
                                                                Histórico
                                                            </span>
                                                            <span className="es-lead-action-count">
                                                                {
                                                                    lead.status_histories_count
                                                                }
                                                            </span>
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
                                                            <>
                                                                <button
                                                                    aria-label="Editar lead"
                                                                    className="es-lead-action"
                                                                    onClick={() =>
                                                                        openLead(
                                                                            lead,
                                                                        )
                                                                    }
                                                                    title="Editar lead"
                                                                    type="button"
                                                                >
                                                                    <i
                                                                        className="fas fa-pen"
                                                                        aria-hidden="true"
                                                                    />
                                                                    <span>
                                                                        Editar
                                                                    </span>
                                                                </button>
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
                                                                    <span>
                                                                        Agendar
                                                                    </span>
                                                                </button>
                                                            </>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                            {historyLeadId === lead.id && (
                                                <tr>
                                                    <td
                                                        className="es-lead-history"
                                                        colSpan={
                                                            selectedUnitId ===
                                                                null &&
                                                            units.length > 1
                                                                ? 8
                                                                : 7
                                                        }
                                                    >
                                                        <strong>
                                                            Histórico de status
                                                        </strong>
                                                        {lead.status_histories
                                                            .length > 0 ? (
                                                            <ol>
                                                                {lead.status_histories.map(
                                                                    (
                                                                        history,
                                                                    ) => (
                                                                        <li
                                                                            key={
                                                                                history.id
                                                                            }
                                                                        >
                                                                            <time
                                                                                dateTime={
                                                                                    history.changed_at
                                                                                }
                                                                            >
                                                                                {new Date(
                                                                                    history.changed_at,
                                                                                ).toLocaleString(
                                                                                    'pt-BR',
                                                                                )}
                                                                            </time>
                                                                            {
                                                                                ' — '
                                                                            }
                                                                            {history
                                                                                .from_status
                                                                                ?.nome ??
                                                                                'Início'}
                                                                            {
                                                                                ' → '
                                                                            }
                                                                            {history
                                                                                .to_status
                                                                                ?.nome ??
                                                                                'Sem status'}
                                                                            {
                                                                                ' — '
                                                                            }
                                                                            {history
                                                                                .changed_by
                                                                                ?.name ??
                                                                                'Sistema/API ou registro inicial'}
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ol>
                                                        ) : (
                                                            <p>
                                                                Nenhuma
                                                                transição de
                                                                status
                                                                registrada.
                                                            </p>
                                                        )}
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
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
