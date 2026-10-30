import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import ListFilters from '@/components/visitas/ListFilters';
import type { FilterableRecord } from '@/components/visitas/ListFilters';
import RecordModal, { Field } from '@/components/visitas/RecordModal';
import StatusPill from '@/components/visitas/StatusPill';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Seller = { id: number; unidade_id: number; nome: string };
type ClassRoom = {
    id: number;
    unidade_id: number;
    nome: string;
    periodo: string;
    capacidade: number;
    active_matriculas_count: number;
};
type Enrollment = {
    id: number;
    turma_id: number;
    status: string;
    turma?: { id: number; nome: string; periodo: string } | null;
};
type Visit = FilterableRecord & {
    status: string | null;
    unidade_id: number;
    unidade?: Unit | null;
    vendedor?: Seller | null;
    matricula_ativa?: Enrollment | null;
    matriculas: Enrollment[];
    horario: string | null;
    telefone2: string | null;
    idade: number | null;
    serie: string | null;
    instituicao: string | null;
    motivo_perda: string | null;
    retorno_data: string | null;
    observacoes: string | null;
};
type VisitData = {
    unidade_id: string;
    vendedor_id: string;
    data: string;
    horario: string;
    responsavel: string;
    telefone: string;
    telefone2: string;
    aluno: string;
    idade: string;
    serie: string;
    instituicao: string;
    observacoes: string;
};
type StatusData = {
    status: string;
    vendedor_id: string;
    motivo_perda: string;
    retorno_data: string;
};
type Props = {
    auth: Auth;
    visits: Visit[];
    units: Unit[];
    selectedUnitId: number | null;
    sellers: Seller[];
    statuses: string[];
    lossReasons: string[];
    classes: ClassRoom[];
};

function localToday(): string {
    const date = new Date();
    const offset = date.getTimezoneOffset();

    return new Date(date.getTime() - offset * 60_000)
        .toISOString()
        .slice(0, 10);
}

function emptyVisit(unitId: number | null, units: Unit[]): VisitData {
    return {
        unidade_id: String(unitId ?? units[0]?.id ?? ''),
        vendedor_id: '',
        data: localToday(),
        horario: '',
        responsavel: '',
        telefone: '',
        telefone2: '',
        aluno: '',
        idade: '',
        serie: '',
        instituicao: '',
        observacoes: '',
    };
}

export default function Visits({
    auth,
    visits,
    units,
    selectedUnitId,
    sellers,
    statuses,
    lossReasons,
    classes,
}: Props) {
    const [editing, setEditing] = useState<Visit | null>(null);
    const [statusVisit, setStatusVisit] = useState<Visit | null>(null);
    const [enrollmentVisit, setEnrollmentVisit] = useState<Visit | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const form = useForm<VisitData>(emptyVisit(selectedUnitId, units));
    const statusForm = useForm<StatusData>({
        status: '',
        vendedor_id: '',
        motivo_perda: '',
        retorno_data: '',
    });
    const enrollmentForm = useForm<{ turma_id: string }>({ turma_id: '' });

    function openVisit(visit: Visit | null = null) {
        setEditing(visit);
        setModalOpen(true);
        form.clearErrors();
        form.setData(
            visit
                ? {
                      unidade_id: String(visit.unidade_id),
                      vendedor_id: visit.vendedor_id
                          ? String(visit.vendedor_id)
                          : '',
                      data: visit.data?.slice(0, 10) ?? '',
                      horario: visit.horario?.slice(0, 5) ?? '',
                      responsavel: visit.responsavel ?? '',
                      telefone: visit.telefone ?? '',
                      telefone2: visit.telefone2 ?? '',
                      aluno: visit.aluno ?? '',
                      idade: visit.idade === null ? '' : String(visit.idade),
                      serie: visit.serie ?? '',
                      instituicao: visit.instituicao ?? '',
                      observacoes: visit.observacoes ?? '',
                  }
                : emptyVisit(selectedUnitId, units),
        );
    }

    function closeVisitModal() {
        setModalOpen(false);
        setEditing(null);
        form.reset();
        form.clearErrors();
    }

    function submitVisit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = { onSuccess: closeVisitModal };

        if (editing) {
            form.put(`/visitas/${editing.id}`, options);
        } else {
            form.post('/visitas', options);
        }
    }

    function openStatus(visit: Visit) {
        setStatusVisit(visit);
        statusForm.clearErrors();
        statusForm.setData({
            status: visit.status ?? 'Aguardando',
            vendedor_id: visit.vendedor_id ? String(visit.vendedor_id) : '',
            motivo_perda: visit.motivo_perda ?? '',
            retorno_data: visit.retorno_data?.slice(0, 10) ?? '',
        });
    }

    function submitStatus(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!statusVisit) {
            return;
        }

        statusForm.patch(`/visitas/${statusVisit.id}/status`, {
            preserveScroll: true,
            onSuccess: () => setStatusVisit(null),
        });
    }

    function openEnrollment(visit: Visit) {
        setEnrollmentVisit(visit);
        enrollmentForm.clearErrors();
        enrollmentForm.setData('turma_id', '');
    }

    function submitEnrollment(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!enrollmentVisit) {
            return;
        }

        enrollmentForm.post(`/visitas/${enrollmentVisit.id}/matriculas`, {
            preserveScroll: true,
            onSuccess: () => setEnrollmentVisit(null),
        });
    }

    function cancelEnrollment(visit: Visit) {
        if (
            !window.confirm(
                `Deseja cancelar a matrícula de ${visit.responsavel}? O histórico será preservado.`,
            )
        ) {
            return;
        }

        router.post(
            `/visitas/${visit.id}/matriculas/cancel`,
            {},
            { preserveScroll: true },
        );
    }

    function removeVisit(visit: Visit) {
        if (
            visit.matriculas.length > 0 ||
            !window.confirm(`Deseja excluir a visita de ${visit.responsavel}?`)
        ) {
            return;
        }

        router.delete(`/visitas/${visit.id}`, { preserveScroll: true });
    }

    return (
        <VisitLayout
            active="visitas"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Visitas"
            units={units}
        >
            <div className="es-header">
                <div>
                    <h1>Visitas</h1>
                    <div className="es-sub">
                        Acompanhamento, retornos e matrículas
                    </div>
                </div>
                <button
                    className="btn btn-primary"
                    onClick={() => openVisit()}
                    type="button"
                >
                    + Visita
                </button>
            </div>
            <ListFilters
                page="visitas"
                rows={visits}
                sellers={sellers}
                statuses={[...statuses, 'Matriculado']}
            >
                {(filteredVisits) => (
                    <div className="es-panel es-table-wrap">
                        <table className="es-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Horário</th>
                                    {selectedUnitId === null &&
                                        units.length > 1 && <th>Unidade</th>}
                                    <th>Responsável</th>
                                    <th>Aluno</th>
                                    <th>Telefone</th>
                                    <th>Vendedor</th>
                                    <th>Status</th>
                                    <th>Matrícula</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredVisits.map((visit) => (
                                    <tr key={visit.id}>
                                        <td>
                                            {visit.data?.slice(0, 10) || '—'}
                                        </td>
                                        <td>
                                            {visit.horario?.slice(0, 5) || '—'}
                                        </td>
                                        {selectedUnitId === null &&
                                            units.length > 1 && (
                                                <td>
                                                    {visit.unidade?.nome ?? '—'}
                                                </td>
                                            )}
                                        <td>{visit.responsavel || '—'}</td>
                                        <td>{visit.aluno || '—'}</td>
                                        <td>{visit.telefone || '—'}</td>
                                        <td>{visit.vendedor?.nome ?? '—'}</td>
                                        <td>
                                            <StatusPill status={visit.status} />
                                        </td>
                                        <td>
                                            {visit.matricula_ativa?.turma
                                                ? `${visit.matricula_ativa.turma.nome} · ${visit.matricula_ativa.turma.periodo}`
                                                : visit.matriculas.length > 0
                                                  ? 'Cancelada'
                                                  : '—'}
                                        </td>
                                        <td>
                                            <button
                                                aria-label="Editar visita"
                                                className="btn btn-secondary es-icon-action"
                                                onClick={() => openVisit(visit)}
                                                title="Editar visita"
                                                type="button"
                                            >
                                                <i
                                                    className="fas fa-pen"
                                                    aria-hidden="true"
                                                />
                                            </button>{' '}
                                            <button
                                                aria-label="Alterar status"
                                                className="btn btn-secondary es-icon-action"
                                                disabled={Boolean(
                                                    visit.matricula_ativa,
                                                )}
                                                onClick={() =>
                                                    openStatus(visit)
                                                }
                                                title={
                                                    visit.matricula_ativa
                                                        ? 'Cancele a matrícula antes de alterar o status'
                                                        : 'Alterar status'
                                                }
                                                type="button"
                                            >
                                                <i
                                                    className="fas fa-list-check"
                                                    aria-hidden="true"
                                                />
                                            </button>{' '}
                                            <button
                                                aria-label={
                                                    visit.matricula_ativa
                                                        ? 'Cancelar matrícula'
                                                        : 'Efetivar matrícula'
                                                }
                                                className="btn btn-primary es-icon-action"
                                                onClick={() =>
                                                    visit.matricula_ativa
                                                        ? cancelEnrollment(
                                                              visit,
                                                          )
                                                        : openEnrollment(visit)
                                                }
                                                title={
                                                    visit.matricula_ativa
                                                        ? 'Cancelar matrícula'
                                                        : 'Efetivar matrícula'
                                                }
                                                type="button"
                                            >
                                                <i
                                                    className={`fas ${visit.matricula_ativa ? 'fa-user-xmark' : 'fa-user-plus'}`}
                                                    aria-hidden="true"
                                                />
                                            </button>{' '}
                                            <button
                                                aria-label="Excluir visita"
                                                className="btn btn-delete es-icon-action"
                                                disabled={
                                                    visit.matriculas.length > 0
                                                }
                                                onClick={() =>
                                                    removeVisit(visit)
                                                }
                                                title={
                                                    visit.matriculas.length > 0
                                                        ? 'Visita com histórico de matrícula'
                                                        : 'Excluir visita'
                                                }
                                                type="button"
                                            >
                                                <i
                                                    className="fas fa-trash"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {filteredVisits.length === 0 && (
                                    <tr>
                                        <td
                                            className="es-empty"
                                            colSpan={
                                                selectedUnitId === null &&
                                                units.length > 1
                                                    ? 10
                                                    : 9
                                            }
                                        >
                                            Nenhuma visita encontrada para os
                                            filtros selecionados.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </ListFilters>
            {modalOpen && (
                <RecordModal
                    onClose={closeVisitModal}
                    onSubmit={submitVisit}
                    processing={form.processing}
                    title={editing ? 'Editar visita' : 'Nova visita'}
                >
                    <Field error={form.errors.unidade_id} label="Unidade">
                        <select
                            disabled={editing !== null}
                            onChange={(event) =>
                                form.setData('unidade_id', event.target.value)
                            }
                            value={form.data.unidade_id}
                        >
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.nome}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field error={form.errors.vendedor_id} label="Vendedor">
                        <select
                            onChange={(event) =>
                                form.setData('vendedor_id', event.target.value)
                            }
                            value={form.data.vendedor_id}
                        >
                            <option value="">Não atribuído</option>
                            {sellers
                                .filter(
                                    (seller) =>
                                        String(seller.unidade_id) ===
                                        form.data.unidade_id,
                                )
                                .map((seller) => (
                                    <option key={seller.id} value={seller.id}>
                                        {seller.nome}
                                    </option>
                                ))}
                        </select>
                    </Field>
                    <Field error={form.errors.data} label="Data">
                        <input
                            onChange={(event) =>
                                form.setData('data', event.target.value)
                            }
                            required
                            type="date"
                            value={form.data.data}
                        />
                    </Field>
                    <Field error={form.errors.horario} label="Horário">
                        <input
                            onChange={(event) =>
                                form.setData('horario', event.target.value)
                            }
                            type="time"
                            value={form.data.horario}
                        />
                    </Field>
                    <Field error={form.errors.responsavel} label="Responsável">
                        <input
                            onChange={(event) =>
                                form.setData('responsavel', event.target.value)
                            }
                            required
                            value={form.data.responsavel}
                        />
                    </Field>
                    <Field error={form.errors.telefone} label="Telefone">
                        <input
                            onChange={(event) =>
                                form.setData('telefone', event.target.value)
                            }
                            value={form.data.telefone}
                        />
                    </Field>
                    <Field error={form.errors.telefone2} label="Telefone 2">
                        <input
                            onChange={(event) =>
                                form.setData('telefone2', event.target.value)
                            }
                            value={form.data.telefone2}
                        />
                    </Field>
                    <Field error={form.errors.aluno} label="Aluno">
                        <input
                            onChange={(event) =>
                                form.setData('aluno', event.target.value)
                            }
                            value={form.data.aluno}
                        />
                    </Field>
                    <Field error={form.errors.idade} label="Idade">
                        <input
                            max="120"
                            min="0"
                            onChange={(event) =>
                                form.setData('idade', event.target.value)
                            }
                            type="number"
                            value={form.data.idade}
                        />
                    </Field>
                    <Field error={form.errors.serie} label="Série">
                        <input
                            onChange={(event) =>
                                form.setData('serie', event.target.value)
                            }
                            value={form.data.serie}
                        />
                    </Field>
                    <Field
                        error={form.errors.instituicao}
                        label="Instituição atual"
                    >
                        <input
                            onChange={(event) =>
                                form.setData('instituicao', event.target.value)
                            }
                            value={form.data.instituicao}
                        />
                    </Field>
                    <Field error={form.errors.observacoes} label="Observações">
                        <textarea
                            onChange={(event) =>
                                form.setData('observacoes', event.target.value)
                            }
                            value={form.data.observacoes}
                        />
                    </Field>
                </RecordModal>
            )}
            {statusVisit && (
                <RecordModal
                    onClose={() => setStatusVisit(null)}
                    onSubmit={submitStatus}
                    processing={statusForm.processing}
                    title={`Status da visita — ${statusVisit.responsavel}`}
                    submitLabel="Atualizar status"
                >
                    <Field error={statusForm.errors.status} label="Status">
                        <select
                            onChange={(event) =>
                                statusForm.setData('status', event.target.value)
                            }
                            value={statusForm.data.status}
                        >
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field
                        error={statusForm.errors.vendedor_id}
                        label="Vendedor responsável"
                    >
                        <select
                            onChange={(event) =>
                                statusForm.setData(
                                    'vendedor_id',
                                    event.target.value,
                                )
                            }
                            required
                            value={statusForm.data.vendedor_id}
                        >
                            <option value="">Selecione</option>
                            {sellers
                                .filter(
                                    (seller) =>
                                        seller.unidade_id ===
                                        statusVisit.unidade_id,
                                )
                                .map((seller) => (
                                    <option key={seller.id} value={seller.id}>
                                        {seller.nome}
                                    </option>
                                ))}
                        </select>
                    </Field>
                    {statusForm.data.status === 'Perdido' && (
                        <Field
                            error={statusForm.errors.motivo_perda}
                            label="Motivo da perda"
                        >
                            <select
                                onChange={(event) =>
                                    statusForm.setData(
                                        'motivo_perda',
                                        event.target.value,
                                    )
                                }
                                required
                                value={statusForm.data.motivo_perda}
                            >
                                <option value="">Selecione</option>
                                {lossReasons.map((reason) => (
                                    <option key={reason} value={reason}>
                                        {reason}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    {statusForm.data.status === 'Follow-up' && (
                        <Field
                            error={statusForm.errors.retorno_data}
                            label="Data de retorno"
                        >
                            <input
                                onChange={(event) =>
                                    statusForm.setData(
                                        'retorno_data',
                                        event.target.value,
                                    )
                                }
                                required
                                type="date"
                                value={statusForm.data.retorno_data}
                            />
                        </Field>
                    )}
                </RecordModal>
            )}
            {enrollmentVisit && (
                <RecordModal
                    onClose={() => setEnrollmentVisit(null)}
                    onSubmit={submitEnrollment}
                    processing={enrollmentForm.processing}
                    title={`Efetivar matrícula — ${enrollmentVisit.responsavel}`}
                    submitLabel="Efetivar matrícula"
                >
                    <Field error={enrollmentForm.errors.turma_id} label="Turma">
                        <select
                            onChange={(event) =>
                                enrollmentForm.setData(
                                    'turma_id',
                                    event.target.value,
                                )
                            }
                            required
                            value={enrollmentForm.data.turma_id}
                        >
                            <option value="">Selecione uma turma</option>
                            {classes
                                .filter(
                                    (classRoom) =>
                                        classRoom.unidade_id ===
                                            enrollmentVisit.unidade_id &&
                                        classRoom.active_matriculas_count <
                                            classRoom.capacidade,
                                )
                                .map((classRoom) => (
                                    <option
                                        key={classRoom.id}
                                        value={classRoom.id}
                                    >
                                        {classRoom.nome} · {classRoom.periodo} ·{' '}
                                        {classRoom.capacidade -
                                            classRoom.active_matriculas_count}{' '}
                                        vaga(s)
                                    </option>
                                ))}
                        </select>
                    </Field>
                    {classes.filter(
                        (classRoom) =>
                            classRoom.unidade_id ===
                                enrollmentVisit.unidade_id &&
                            classRoom.active_matriculas_count <
                                classRoom.capacidade,
                    ).length === 0 && (
                        <p className="field-error">
                            Não há turmas com vagas disponíveis nesta unidade.
                        </p>
                    )}
                </RecordModal>
            )}
        </VisitLayout>
    );
}
