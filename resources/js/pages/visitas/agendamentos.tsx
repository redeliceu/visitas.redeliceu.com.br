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
type Appointment = FilterableRecord & {
    status: string | null;
    unidade_id: number;
    unidade?: { id: number; nome: string } | null;
    vendedor?: { id: number; nome: string } | null;
    lead_id: number | null;
    visita_id: number | null;
    horario: string;
    observacoes: string | null;
};
type AppointmentData = {
    unidade_id: string;
    vendedor_id: string;
    data: string;
    horario: string;
    responsavel: string;
    telefone: string;
    aluno: string;
    observacoes: string;
    status: string;
};
type Props = {
    auth: Auth;
    appointments: Appointment[];
    units: Unit[];
    selectedUnitId: number | null;
    sellers: Seller[];
    statuses: string[];
};

function localToday(): string {
    const date = new Date();
    const offset = date.getTimezoneOffset();

    return new Date(date.getTime() - offset * 60_000)
        .toISOString()
        .slice(0, 10);
}

function emptyAppointment(
    unitId: number | null,
    units: Unit[],
): AppointmentData {
    return {
        unidade_id: String(unitId ?? units[0]?.id ?? ''),
        vendedor_id: '',
        data: localToday(),
        horario: '',
        responsavel: '',
        telefone: '',
        aluno: '',
        observacoes: '',
        status: 'Agendado',
    };
}

export default function Appointments({
    auth,
    appointments,
    units,
    selectedUnitId,
    sellers,
    statuses,
}: Props) {
    const [editing, setEditing] = useState<Appointment | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const form = useForm<AppointmentData>(
        emptyAppointment(selectedUnitId, units),
    );

    function openAppointment(appointment: Appointment | null = null) {
        setEditing(appointment);
        setModalOpen(true);
        form.clearErrors();
        form.setData(
            appointment
                ? {
                      unidade_id: String(appointment.unidade_id),
                      vendedor_id: appointment.vendedor_id
                          ? String(appointment.vendedor_id)
                          : '',
                      data: appointment.data?.slice(0, 10) ?? '',
                      horario: appointment.horario?.slice(0, 5) ?? '',
                      responsavel: appointment.responsavel ?? '',
                      telefone: appointment.telefone ?? '',
                      aluno: appointment.aluno ?? '',
                      observacoes: appointment.observacoes ?? '',
                      status: appointment.status ?? 'Agendado',
                  }
                : emptyAppointment(selectedUnitId, units),
        );
    }

    function closeModal() {
        setModalOpen(false);
        setEditing(null);
        form.reset();
        form.clearErrors();
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = { onSuccess: closeModal };

        if (editing) {
            form.put(`/agendamentos/${editing.id}`, options);
        } else {
            form.post('/agendamentos', options);
        }
    }

    function removeAppointment(appointment: Appointment) {
        if (
            appointment.visita_id !== null ||
            !window.confirm('Deseja excluir este agendamento?')
        ) {
            return;
        }

        router.delete(`/agendamentos/${appointment.id}`, {
            preserveScroll: true,
        });
    }

    function convertAppointment(appointment: Appointment) {
        if (
            !window.confirm(
                `Registrar o comparecimento de ${appointment.responsavel} e transformar este agendamento em visita?`,
            )
        ) {
            return;
        }

        router.post(
            `/agendamentos/${appointment.id}/convert`,
            {},
            { preserveScroll: true },
        );
    }

    return (
        <VisitLayout
            active="agendamentos"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Agendamentos"
            units={units}
        >
            <div className="es-header">
                <div>
                    <h1>Agendamentos</h1>
                    <div className="es-sub">
                        Registro manual nesta etapa • preparado para central
                        futura
                    </div>
                </div>
                <button
                    className="btn btn-primary"
                    onClick={() => openAppointment()}
                    type="button"
                >
                    + Agendamento
                </button>
            </div>
            <ListFilters
                page="agendamentos"
                rows={appointments}
                sellers={sellers}
                statuses={statuses}
            >
                {(filteredAppointments) => (
                    <div id="agendamentos-results">
                        <div className="es-panel es-table-wrap">
                            <table className="es-table">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Horário</th>
                                        {selectedUnitId === null &&
                                            units.length > 1 && (
                                                <th>Unidade</th>
                                            )}
                                        <th>Responsável</th>
                                        <th>Aluno</th>
                                        <th>Telefone</th>
                                        <th>Vendedor</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredAppointments.map((appointment) => (
                                        <tr key={appointment.id}>
                                            <td>
                                                {appointment.data?.slice(
                                                    0,
                                                    10,
                                                ) || '—'}
                                            </td>
                                            <td>
                                                {appointment.horario?.slice(
                                                    0,
                                                    5,
                                                ) || '—'}
                                            </td>
                                            {selectedUnitId === null &&
                                                units.length > 1 && (
                                                    <td>
                                                        {appointment.unidade
                                                            ?.nome ?? '—'}
                                                    </td>
                                                )}
                                            <td>
                                                {appointment.responsavel || '—'}
                                            </td>
                                            <td>{appointment.aluno || '—'}</td>
                                            <td>
                                                {appointment.telefone || '—'}
                                            </td>
                                            <td>
                                                {appointment.vendedor?.nome ??
                                                    '—'}
                                            </td>
                                            <td>
                                                <StatusPill
                                                    status={appointment.status}
                                                />
                                            </td>
                                            <td>
                                                <button
                                                    aria-label="Editar agendamento"
                                                    className="btn btn-secondary es-icon-action"
                                                    onClick={() =>
                                                        openAppointment(
                                                            appointment,
                                                        )
                                                    }
                                                    title="Editar agendamento"
                                                    type="button"
                                                >
                                                    <i
                                                        className="fas fa-pen"
                                                        aria-hidden="true"
                                                    />
                                                </button>{' '}
                                                <button
                                                    aria-label={
                                                        appointment.visita_id
                                                            ? 'Visita já registrada'
                                                            : 'Transformar em visita'
                                                    }
                                                    className="btn btn-primary es-icon-action"
                                                    disabled={
                                                        appointment.visita_id !==
                                                            null ||
                                                        appointment.status ===
                                                            'Cancelado'
                                                    }
                                                    onClick={() =>
                                                        convertAppointment(
                                                            appointment,
                                                        )
                                                    }
                                                    title={
                                                        appointment.visita_id
                                                            ? 'Visita já registrada'
                                                            : appointment.status ===
                                                                'Cancelado'
                                                              ? 'Agendamento cancelado'
                                                              : 'Transformar em visita'
                                                    }
                                                    type="button"
                                                >
                                                    <i
                                                        className="fas fa-arrow-right-to-bracket"
                                                        aria-hidden="true"
                                                    />
                                                </button>{' '}
                                                <button
                                                    aria-label="Excluir agendamento"
                                                    className="btn btn-delete es-icon-action"
                                                    disabled={
                                                        appointment.visita_id !==
                                                        null
                                                    }
                                                    onClick={() =>
                                                        removeAppointment(
                                                            appointment,
                                                        )
                                                    }
                                                    title={
                                                        appointment.visita_id !==
                                                        null
                                                            ? 'Agendamento vinculado a uma visita'
                                                            : 'Excluir agendamento'
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
                                    {filteredAppointments.length === 0 && (
                                        <tr>
                                            <td
                                                className="es-empty"
                                                colSpan={
                                                    selectedUnitId === null &&
                                                    units.length > 1
                                                        ? 9
                                                        : 8
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
            {modalOpen && (
                <RecordModal
                    onClose={closeModal}
                    onSubmit={submit}
                    processing={form.processing}
                    title={editing ? 'Editar agendamento' : 'Novo agendamento'}
                >
                    <Field error={form.errors.unidade_id} label="Unidade">
                        <select
                            disabled={editing?.lead_id != null}
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
                            required
                            type="time"
                            value={form.data.horario}
                        />
                    </Field>
                    <Field
                        error={form.errors.responsavel}
                        label="Responsável *"
                    >
                        <input
                            onChange={(event) =>
                                form.setData('responsavel', event.target.value)
                            }
                            required
                            value={form.data.responsavel}
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
                    <Field error={form.errors.telefone} label="Telefone">
                        <input
                            onChange={(event) =>
                                form.setData('telefone', event.target.value)
                            }
                            value={form.data.telefone}
                        />
                    </Field>
                    <Field error={form.errors.status} label="Status">
                        <select
                            onChange={(event) =>
                                form.setData('status', event.target.value)
                            }
                            value={form.data.status}
                        >
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </select>
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
        </VisitLayout>
    );
}
