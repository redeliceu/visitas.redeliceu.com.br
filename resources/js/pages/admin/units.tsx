import { Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import ConfirmDialog from '@/components/visitas/ConfirmDialog';
import RecordModal from '@/components/visitas/RecordModal';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = {
    id: number;
    nome: string;
    ativo: boolean;
    sellflux_configurado: boolean;
    sellflux_projeto: string | null;
    sellflux_atualizado_em: string | null;
    atendentes_pendentes: number;
};
type Props = { units: Unit[] };

const emptyForm = {
    nome: '',
    sellflux_token: '',
    remover_sellflux_token: false,
};

export default function Units({ units }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Unit | null>(null);
    const [showToken, setShowToken] = useState(false);
    const [testingId, setTestingId] = useState<number | null>(null);
    const [archiving, setArchiving] = useState<Unit | null>(null);
    const form = useForm(emptyForm);
    const pending = units.reduce(
        (total, unit) => total + unit.atendentes_pendentes,
        0,
    );

    function openCreate() {
        setEditing(null);
        form.setData({ ...emptyForm });
        form.clearErrors();
        setShowToken(false);
        setModalOpen(true);
    }

    function openEdit(unit: Unit) {
        setEditing(unit);
        form.setData({ ...emptyForm, nome: unit.nome });
        form.clearErrors();
        setShowToken(false);
        setModalOpen(true);
    }

    function closeModal() {
        setModalOpen(false);
        setEditing(null);
        form.clearErrors();
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: closeModal };

        if (editing) {
            form.put(`/admin/units/${editing.id}`, options);
        } else {
            form.post('/admin/units', options);
        }
    }

    function testConnection(unit: Unit) {
        router.post(
            `/admin/units/${unit.id}/sellflux-test`,
            {},
            {
                preserveScroll: true,
                onStart: () => setTestingId(unit.id),
                onFinish: () => setTestingId(null),
            },
        );
    }

    function toggleActive() {
        if (!archiving) {
            return;
        }

        router.patch(
            `/admin/units/${archiving.id}/active`,
            { ativo: !archiving.ativo },
            { preserveScroll: true, onFinish: () => setArchiving(null) },
        );
    }

    return (
        <VisitLayout
            active="admin-units"
            auth={auth}
            selectedUnitId={null}
            title="Unidades"
            units={[]}
        >
            <div className="es-header">
                <div>
                    <h1>Unidades</h1>
                    <div className="es-sub">
                        Escolas da rede e a conexão de cada uma com a SellFlux
                    </div>
                </div>
                <div className="es-header-actions">
                    <button
                        className="btn btn-primary"
                        onClick={openCreate}
                        type="button"
                    >
                        <i className="fas fa-plus" aria-hidden="true" /> Nova
                        unidade
                    </button>
                </div>
            </div>

            <div className="es-grid adm-stats">
                <div className="es-stat">
                    <small>Unidades ativas</small>
                    <strong>{units.filter((unit) => unit.ativo).length}</strong>
                </div>
                <div className="es-stat">
                    <small>Arquivadas</small>
                    <strong>
                        {units.filter((unit) => !unit.ativo).length}
                    </strong>
                </div>
                <div className="es-stat">
                    <small>Conectadas à SellFlux</small>
                    <strong>
                        {
                            units.filter((unit) => unit.sellflux_configurado)
                                .length
                        }
                    </strong>
                </div>
                <div className="es-stat">
                    <small>Atendentes pendentes</small>
                    <strong>{pending}</strong>
                </div>
            </div>

            <section className="es-panel">
                <div className="es-table-wrap">
                    <table className="es-table adm-table">
                        <thead>
                            <tr>
                                <th>Unidade</th>
                                <th>Situação</th>
                                <th>SellFlux</th>
                                <th aria-label="Ações" />
                            </tr>
                        </thead>
                        <tbody>
                            {units.map((unit) => (
                                <tr
                                    className={unit.ativo ? '' : 'adm-muted'}
                                    key={unit.id}
                                >
                                    <td>
                                        <div className="adm-person">
                                            <span className="adm-avatar square">
                                                <i
                                                    className="fas fa-school"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <strong>{unit.nome}</strong>
                                        </div>
                                    </td>
                                    <td data-label="Situação">
                                        <span
                                            className={`es-pill ${unit.ativo ? 'green' : 'slate'}`}
                                        >
                                            {unit.ativo ? 'Ativa' : 'Arquivada'}
                                        </span>
                                    </td>
                                    <td data-label="SellFlux">
                                        {unit.sellflux_configurado ? (
                                            <>
                                                <span className="es-pill blue">
                                                    <i
                                                        className="fas fa-plug"
                                                        aria-hidden="true"
                                                    />{' '}
                                                    {unit.sellflux_projeto
                                                        ? `Projeto ${unit.sellflux_projeto}`
                                                        : 'Token configurado'}
                                                </span>
                                                {unit.sellflux_atualizado_em && (
                                                    <div className="es-sub">
                                                        Token alterado em{' '}
                                                        {
                                                            unit.sellflux_atualizado_em
                                                        }
                                                    </div>
                                                )}
                                            </>
                                        ) : (
                                            <span className="es-sub">
                                                Não configurado
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <div className="adm-row-actions">
                                            <button
                                                className="adm-action"
                                                onClick={() => openEdit(unit)}
                                                type="button"
                                            >
                                                <i
                                                    className="fas fa-pen"
                                                    aria-hidden="true"
                                                />
                                                Editar
                                            </button>
                                            {unit.sellflux_configurado && (
                                                <button
                                                    className="adm-action"
                                                    disabled={
                                                        testingId !== null
                                                    }
                                                    onClick={() =>
                                                        testConnection(unit)
                                                    }
                                                    type="button"
                                                >
                                                    <i
                                                        className={`fas ${testingId === unit.id ? 'fa-spinner fa-spin' : 'fa-satellite-dish'}`}
                                                        aria-hidden="true"
                                                    />
                                                    {testingId === unit.id
                                                        ? 'Testando...'
                                                        : 'Testar conexão'}
                                                </button>
                                            )}
                                            {unit.sellflux_configurado && (
                                                <Link
                                                    className="adm-action"
                                                    href={`/admin/units/${unit.id}/atendentes`}
                                                >
                                                    <i
                                                        className="fas fa-headset"
                                                        aria-hidden="true"
                                                    />
                                                    Atendentes
                                                    {unit.atendentes_pendentes >
                                                        0 && (
                                                        <span className="adm-badge">
                                                            {
                                                                unit.atendentes_pendentes
                                                            }
                                                        </span>
                                                    )}
                                                </Link>
                                            )}
                                            <button
                                                className={`adm-action${unit.ativo ? ' danger' : ''}`}
                                                onClick={() =>
                                                    setArchiving(unit)
                                                }
                                                type="button"
                                            >
                                                <i
                                                    className={`fas ${unit.ativo ? 'fa-box-archive' : 'fa-rotate-left'}`}
                                                    aria-hidden="true"
                                                />
                                                {unit.ativo
                                                    ? 'Arquivar'
                                                    : 'Reativar'}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {units.length === 0 && (
                                <tr>
                                    <td className="es-empty" colSpan={4}>
                                        Nenhuma unidade cadastrada.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            {modalOpen && (
                <RecordModal
                    className="adm-modal"
                    onClose={closeModal}
                    onSubmit={submit}
                    processing={form.processing}
                    submitLabel={editing ? 'Salvar alterações' : 'Cadastrar'}
                    title={
                        editing
                            ? `Editar unidade: ${editing.nome}`
                            : 'Nova unidade'
                    }
                >
                    <label className="adm-span">
                        Nome da unidade
                        <input
                            autoFocus
                            onChange={(event) =>
                                form.setData('nome', event.target.value)
                            }
                            required
                            value={form.data.nome}
                        />
                        {form.errors.nome && (
                            <span className="field-error">
                                {form.errors.nome}
                            </span>
                        )}
                    </label>

                    <p className="adm-section-title">
                        Integração com a SellFlux
                    </p>
                    {editing?.sellflux_configurado && (
                        <div
                            className={`adm-span adm-callout${form.data.remover_sellflux_token ? ' warning' : ''}`}
                        >
                            <i
                                className={`fas ${form.data.remover_sellflux_token ? 'fa-triangle-exclamation' : 'fa-circle-check'}`}
                                aria-hidden="true"
                            />
                            <span>
                                {form.data.remover_sellflux_token
                                    ? 'O token será removido ao salvar. A unidade deixa de ser consultada na SellFlux.'
                                    : `Token configurado${editing.sellflux_projeto ? ` (projeto ${editing.sellflux_projeto})` : ''}. Por segurança, ele nunca é exibido.`}
                            </span>
                        </div>
                    )}
                    <label className="adm-span">
                        {editing?.sellflux_configurado
                            ? 'Trocar token'
                            : 'Token do projeto'}
                        <span className="adm-input-group">
                            <input
                                autoComplete="new-password"
                                disabled={form.data.remover_sellflux_token}
                                onChange={(event) =>
                                    form.setData(
                                        'sellflux_token',
                                        event.target.value,
                                    )
                                }
                                placeholder={
                                    editing?.sellflux_configurado
                                        ? 'Deixe em branco para manter o atual'
                                        : 'Opcional: cole o token do projeto'
                                }
                                type={showToken ? 'text' : 'password'}
                                value={form.data.sellflux_token}
                            />
                            <button
                                aria-label={
                                    showToken
                                        ? 'Ocultar token'
                                        : 'Mostrar token'
                                }
                                className="adm-input-btn"
                                disabled={form.data.remover_sellflux_token}
                                onClick={() => setShowToken((on) => !on)}
                                type="button"
                            >
                                <i
                                    className={`fas ${showToken ? 'fa-eye-slash' : 'fa-eye'}`}
                                    aria-hidden="true"
                                />
                            </button>
                        </span>
                        <small className="adm-hint">
                            O token é validado na SellFlux e guardado
                            criptografado.
                        </small>
                        {form.errors.sellflux_token && (
                            <span className="field-error">
                                {form.errors.sellflux_token}
                            </span>
                        )}
                    </label>
                    {editing?.sellflux_configurado && (
                        <label className="adm-span adm-switch">
                            <input
                                checked={form.data.remover_sellflux_token}
                                onChange={(event) =>
                                    form.setData({
                                        ...form.data,
                                        sellflux_token: '',
                                        remover_sellflux_token:
                                            event.target.checked,
                                    })
                                }
                                type="checkbox"
                            />
                            <span>
                                <strong>Remover o token desta unidade</strong>
                                <small>
                                    A tarefa de vínculo automático para de
                                    consultar esta unidade.
                                </small>
                            </span>
                        </label>
                    )}
                </RecordModal>
            )}

            {archiving && (
                <ConfirmDialog
                    confirmLabel={archiving.ativo ? 'Arquivar' : 'Reativar'}
                    onClose={() => setArchiving(null)}
                    onConfirm={toggleActive}
                    title={
                        archiving.ativo
                            ? `Arquivar ${archiving.nome}`
                            : `Reativar ${archiving.nome}`
                    }
                    tone={archiving.ativo ? 'danger' : 'primary'}
                >
                    <p>
                        {archiving.ativo
                            ? 'A unidade fica arquivada. Os dados continuam guardados, e você pode reativá-la depois.'
                            : 'A unidade volta a ficar ativa no sistema.'}
                    </p>
                </ConfirmDialog>
            )}
        </VisitLayout>
    );
}
