import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import RecordModal, { Field } from '@/components/visitas/RecordModal';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Enrollment = {
    id: number;
    data: string;
    visita?: {
        aluno: string | null;
        responsavel: string | null;
        serie: string | null;
    } | null;
};
type ClassRoom = {
    id: number;
    unidade_id: number;
    nome: string;
    periodo: string;
    capacidade: number;
    sala: string | null;
    active_matriculas_count: number;
    unidade?: Unit | null;
    matriculas?: Enrollment[];
};
type ClassData = {
    unidade_id: string;
    nome: string;
    periodo: string;
    sala: string;
    capacidade: string;
};
type BulkData = {
    unidade_id: string;
    periodo: string;
    quantidade: string;
    inicio: string;
};
type Props = {
    auth: Auth;
    classes: ClassRoom[];
    units: Unit[];
    selectedUnitId: number | null;
    selectedClass: ClassRoom | null;
    periods: string[];
};

function emptyClass(unitId: number | null, units: Unit[]): ClassData {
    return {
        unidade_id: String(unitId ?? units[0]?.id ?? ''),
        nome: '',
        periodo: 'Manhã',
        sala: '',
        capacidade: '25',
    };
}

export default function Classes({
    auth,
    classes,
    units,
    selectedUnitId,
    selectedClass,
    periods,
}: Props) {
    const canManage =
        auth.user?.role === 'master' || auth.user?.role === 'gestor';
    const [period, setPeriod] = useState('Manhã');
    const [editing, setEditing] = useState<ClassRoom | null>(null);
    const [classModalOpen, setClassModalOpen] = useState(false);
    const [bulkModalOpen, setBulkModalOpen] = useState(false);
    const form = useForm<ClassData>(emptyClass(selectedUnitId, units));
    const bulkForm = useForm<BulkData>({
        unidade_id: String(selectedUnitId ?? units[0]?.id ?? ''),
        periodo: 'Manhã',
        quantidade: '20',
        inicio: '',
    });

    const visibleClasses = useMemo(
        () => classes.filter((classRoom) => classRoom.periodo === period),
        [classes, period],
    );
    const totals = useMemo(
        () =>
            visibleClasses.reduce(
                (summary, classRoom) => ({
                    capacity: summary.capacity + classRoom.capacidade,
                    occupied:
                        summary.occupied + classRoom.active_matriculas_count,
                }),
                { capacity: 0, occupied: 0 },
            ),
        [visibleClasses],
    );

    function openClass(classRoom: ClassRoom | null = null) {
        setEditing(classRoom);
        form.clearErrors();
        form.setData(
            classRoom
                ? {
                      unidade_id: String(classRoom.unidade_id),
                      nome: classRoom.nome,
                      periodo: classRoom.periodo,
                      sala: classRoom.sala ?? '',
                      capacidade: String(classRoom.capacidade),
                  }
                : emptyClass(selectedUnitId, units),
        );
        setClassModalOpen(true);
    }

    function submitClass(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setClassModalOpen(false),
        };

        if (editing) {
            form.put(`/turmas/${editing.id}`, options);
        } else {
            form.post('/turmas', options);
        }
    }

    function submitBulk(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        bulkForm.post('/turmas/criar-em-lote', {
            preserveScroll: true,
            onSuccess: () => setBulkModalOpen(false),
        });
    }

    function openClassDetails(classRoom: ClassRoom) {
        router.get(
            `/turmas/${classRoom.id}`,
            selectedUnitId ? { unidade_id: selectedUnitId } : {},
            { preserveScroll: true },
        );
    }

    function closeClassDetails() {
        router.get(
            '/turmas',
            selectedUnitId ? { unidade_id: selectedUnitId } : {},
            { preserveScroll: true, replace: true },
        );
    }

    function deleteClass(classRoom: ClassRoom) {
        if (
            !window.confirm(
                `Excluir a turma ${classRoom.nome} — sala ${classRoom.sala ?? 'não definida'}?`,
            )
        ) {
            return;
        }

        router.delete(`/turmas/${classRoom.id}`, { preserveScroll: true });
    }

    return (
        <VisitLayout
            active="turmas"
            auth={auth}
            selectedUnitId={selectedUnitId}
            title="Mapa de Turmas"
            units={units}
        >
            <div className="es-header">
                <div>
                    <h1>Mapa de Turmas</h1>
                    <div className="es-sub">
                        Salas, ocupação e vagas por unidade e período
                    </div>
                </div>
                {canManage && (
                    <div className="es-header-actions">
                        <button
                            className="btn btn-secondary"
                            onClick={() => {
                                bulkForm.clearErrors();
                                bulkForm.setData(
                                    'unidade_id',
                                    String(
                                        selectedUnitId ?? units[0]?.id ?? '',
                                    ),
                                );
                                bulkForm.setData('periodo', period);
                                setBulkModalOpen(true);
                            }}
                            type="button"
                        >
                            + Criar salas em massa
                        </button>
                        <button
                            className="btn btn-primary"
                            onClick={() => openClass()}
                            type="button"
                        >
                            + Nova turma
                        </button>
                    </div>
                )}
            </div>

            <div className="es-grid">
                <div className="es-stat">
                    <small>Turmas no período</small>
                    <strong>{visibleClasses.length}</strong>
                </div>
                <div className="es-stat">
                    <small>Capacidade</small>
                    <strong>{totals.capacity}</strong>
                </div>
                <div className="es-stat">
                    <small>Matrículas ativas</small>
                    <strong>{totals.occupied}</strong>
                </div>
                <div className="es-stat">
                    <small>Vagas livres</small>
                    <strong>
                        {Math.max(0, totals.capacity - totals.occupied)}
                    </strong>
                </div>
            </div>

            <div aria-label="Período" className="es-period" role="group">
                {periods.map((item) => (
                    <button
                        aria-pressed={period === item}
                        className={period === item ? 'active' : ''}
                        key={item}
                        onClick={() => setPeriod(item)}
                        type="button"
                    >
                        {item}
                    </button>
                ))}
            </div>

            <div className="es-classes">
                {visibleClasses.map((classRoom) => {
                    const occupied = classRoom.active_matriculas_count;
                    const free = Math.max(0, classRoom.capacidade - occupied);
                    const progress = classRoom.capacidade
                        ? Math.min(100, (occupied / classRoom.capacidade) * 100)
                        : 0;

                    return (
                        <article
                            className="es-class es-class-click"
                            key={classRoom.id}
                        >
                            <button
                                aria-label={`Ver matrículas de ${classRoom.nome}, sala ${classRoom.sala ?? 'não definida'}`}
                                className="es-class-detail-trigger"
                                onClick={() => openClassDetails(classRoom)}
                                type="button"
                            >
                                <h2>Sala {classRoom.sala || 'não definida'}</h2>
                                <div className="es-sub es-series">
                                    {classRoom.nome || 'Série não definida'}
                                </div>
                            </button>
                            <div className="es-sub">
                                {classRoom.unidade?.nome ?? 'Unidade'}
                            </div>
                            <div
                                aria-label={`${occupied} de ${classRoom.capacidade} vagas ocupadas`}
                                className="es-bar"
                                role="img"
                            >
                                <span style={{ width: `${progress}%` }} />
                            </div>
                            <div>
                                {occupied} ocupadas de {classRoom.capacidade}{' '}
                                vagas
                            </div>
                            <footer>
                                <span
                                    className={`es-pill ${free ? 'green' : 'red'}`}
                                >
                                    {free} vagas livres
                                </span>
                                {canManage && (
                                    <div className="es-class-actions">
                                        <button
                                            className="btn btn-secondary"
                                            onClick={() => openClass(classRoom)}
                                            type="button"
                                        >
                                            Editar slots
                                        </button>
                                        <button
                                            className="btn btn-delete"
                                            onClick={() =>
                                                deleteClass(classRoom)
                                            }
                                            type="button"
                                        >
                                            Excluir
                                        </button>
                                    </div>
                                )}
                            </footer>
                        </article>
                    );
                })}
                {visibleClasses.length === 0 && (
                    <div className="es-empty">
                        Nenhuma turma cadastrada neste período.
                    </div>
                )}
            </div>

            {canManage && classModalOpen && (
                <RecordModal
                    onClose={() => setClassModalOpen(false)}
                    onSubmit={submitClass}
                    processing={form.processing}
                    title={editing ? 'Editar turma' : 'Nova turma'}
                >
                    {units.length > 1 && (
                        <Field error={form.errors.unidade_id} label="Unidade">
                            <select
                                onChange={(event) =>
                                    form.setData(
                                        'unidade_id',
                                        event.target.value,
                                    )
                                }
                                required
                                value={form.data.unidade_id}
                            >
                                {units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.nome}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    <Field error={form.errors.nome} label="Turma / Série">
                        <input
                            onChange={(event) =>
                                form.setData('nome', event.target.value)
                            }
                            required
                            value={form.data.nome}
                        />
                    </Field>
                    <Field error={form.errors.periodo} label="Período">
                        <select
                            onChange={(event) =>
                                form.setData('periodo', event.target.value)
                            }
                            value={form.data.periodo}
                        >
                            {periods.map((item) => (
                                <option key={item} value={item}>
                                    {item}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field error={form.errors.sala} label="Sala">
                        <input
                            onChange={(event) =>
                                form.setData('sala', event.target.value)
                            }
                            value={form.data.sala}
                        />
                    </Field>
                    <Field
                        error={form.errors.capacidade}
                        label="Quantidade de vagas"
                    >
                        <input
                            min="0"
                            onChange={(event) =>
                                form.setData('capacidade', event.target.value)
                            }
                            required
                            type="number"
                            value={form.data.capacidade}
                        />
                    </Field>
                </RecordModal>
            )}

            {canManage && bulkModalOpen && (
                <RecordModal
                    onClose={() => setBulkModalOpen(false)}
                    onSubmit={submitBulk}
                    processing={bulkForm.processing}
                    title="Criar salas em massa"
                    submitLabel="Criar salas"
                >
                    {units.length > 1 && (
                        <Field
                            error={bulkForm.errors.unidade_id}
                            label="Unidade"
                        >
                            <select
                                onChange={(event) =>
                                    bulkForm.setData(
                                        'unidade_id',
                                        event.target.value,
                                    )
                                }
                                required
                                value={bulkForm.data.unidade_id}
                            >
                                {units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.nome}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    <Field error={bulkForm.errors.periodo} label="Período">
                        <select
                            onChange={(event) =>
                                bulkForm.setData('periodo', event.target.value)
                            }
                            value={bulkForm.data.periodo}
                        >
                            {periods.map((item) => (
                                <option key={item} value={item}>
                                    {item}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field
                        error={bulkForm.errors.quantidade}
                        label="Quantidade de salas (1 a 100)"
                    >
                        <input
                            max="100"
                            min="1"
                            onChange={(event) =>
                                bulkForm.setData(
                                    'quantidade',
                                    event.target.value,
                                )
                            }
                            required
                            type="number"
                            value={bulkForm.data.quantidade}
                        />
                    </Field>
                    <Field
                        error={bulkForm.errors.inicio}
                        label="Número inicial (opcional)"
                    >
                        <input
                            min="1"
                            onChange={(event) =>
                                bulkForm.setData('inicio', event.target.value)
                            }
                            type="number"
                            value={bulkForm.data.inicio}
                        />
                    </Field>
                    <p className="es-bulk-note">
                        As salas começam com série “A definir” e zero vagas.
                        Configure a série e a capacidade após criá-las. A
                        numeração deve ser única por unidade e período.
                    </p>
                </RecordModal>
            )}

            {selectedClass && (
                <RecordModal
                    onClose={closeClassDetails}
                    onSubmit={(event) => event.preventDefault()}
                    processing={false}
                    title={`${selectedClass.nome} • ${selectedClass.periodo}`}
                    hideActions
                >
                    <div className="es-class-detail">
                        <div className="es-grid">
                            <div className="es-stat">
                                <small>Alunos matriculados</small>
                                <strong>
                                    {selectedClass.matriculas?.length ?? 0}
                                </strong>
                            </div>
                            <div className="es-stat">
                                <small>Vagas disponíveis</small>
                                <strong>
                                    {Math.max(
                                        0,
                                        selectedClass.capacidade -
                                            (selectedClass.matriculas?.length ??
                                                0),
                                    )}
                                </strong>
                            </div>
                        </div>
                        <p className="es-sub">
                            {selectedClass.unidade?.nome} • Sala{' '}
                            {selectedClass.sala || 'não definida'} • Capacidade{' '}
                            {selectedClass.capacidade}
                        </p>
                        <div className="es-table-wrap">
                            <table className="es-table">
                                <thead>
                                    <tr>
                                        <th>Aluno</th>
                                        <th>Responsável</th>
                                        <th>Série</th>
                                        <th>Data da matrícula</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {selectedClass.matriculas?.map(
                                        (enrollment) => (
                                            <tr key={enrollment.id}>
                                                <td>
                                                    {enrollment.visita?.aluno ??
                                                        'Registro não encontrado'}
                                                </td>
                                                <td>
                                                    {enrollment.visita
                                                        ?.responsavel ?? '—'}
                                                </td>
                                                <td>
                                                    {enrollment.visita?.serie ??
                                                        '—'}
                                                </td>
                                                <td>
                                                    {enrollment.data.slice(
                                                        0,
                                                        10,
                                                    )}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                    {!selectedClass.matriculas?.length && (
                                        <tr>
                                            <td
                                                className="es-empty"
                                                colSpan={4}
                                            >
                                                Nenhuma matrícula ativa nesta
                                                turma.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </RecordModal>
            )}
        </VisitLayout>
    );
}
