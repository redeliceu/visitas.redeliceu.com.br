import { Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import ConfirmDialog from '@/components/visitas/ConfirmDialog';
import RecordModal from '@/components/visitas/RecordModal';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Seller = {
    id: number;
    nome: string;
    ativo: boolean;
    tem_conta: boolean;
    bloqueio_reativacao: string | null;
};
type Suggestion =
    | { tipo: 'vendedor'; vendedor_id: number; reativar: boolean }
    | { tipo: 'criar' }
    | { tipo: 'bloqueado'; motivo: string }
    | null;
type Situation = 'pendente' | 'associado' | 'ignorado';
type Attendant = {
    id: number;
    nome: string;
    situacao: Situation;
    vendedor_id: number | null;
    vendedor_nome: string | null;
    vendedor_ativo: boolean | null;
    visto_em: string | null;
    sugestao: Suggestion;
};
type Props = {
    unit: {
        id: number;
        nome: string;
        ativo: boolean;
        sellflux_configurado: boolean;
    };
    semVendedorAtivo: boolean;
    sellers: Seller[];
    atendentes: Attendant[];
};

const NEW_SELLER = 'criar';

const tabs: Array<{ key: Situation | 'todos'; label: string }> = [
    { key: 'pendente', label: 'Pendentes' },
    { key: 'associado', label: 'Associados' },
    { key: 'ignorado', label: 'Ignorados' },
    { key: 'todos', label: 'Todos' },
];

function initialChoice(attendant: Attendant): string {
    if (attendant.vendedor_id) {
        return String(attendant.vendedor_id);
    }

    if (attendant.sugestao?.tipo === 'vendedor') {
        return String(attendant.sugestao.vendedor_id);
    }

    return attendant.sugestao?.tipo === 'criar' ? NEW_SELLER : '';
}

function SuggestionNote({
    attendant,
    sellers,
}: {
    attendant: Attendant;
    sellers: Seller[];
}) {
    const suggestion = attendant.sugestao;

    if (attendant.situacao !== 'pendente' || !suggestion) {
        return null;
    }

    if (suggestion.tipo === 'bloqueado') {
        return (
            <div className="es-sub adm-warn-text">
                Cadastro inativo com este nome não pode ser reativado:{' '}
                {suggestion.motivo}
            </div>
        );
    }

    const text =
        suggestion.tipo === 'criar'
            ? 'Sugestão: criar vendedor novo'
            : `Sugestão: ${suggestion.reativar ? 'reativar ' : ''}${sellers.find((seller) => seller.id === suggestion.vendedor_id)?.nome ?? ''}`;

    return (
        <div className="es-sub adm-suggestion">
            <i className="fas fa-wand-magic-sparkles" aria-hidden="true" />{' '}
            {text}
        </div>
    );
}

function AssociateModal({
    attendant,
    sellers,
    updateUrl,
    onClose,
}: {
    attendant: Attendant;
    sellers: Seller[];
    updateUrl: string;
    onClose: () => void;
}) {
    const [choice, setChoice] = useState(initialChoice(attendant));
    const [newName, setNewName] = useState(attendant.nome);
    const [saving, setSaving] = useState(false);
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const suggestedId =
        attendant.sugestao?.tipo === 'vendedor'
            ? attendant.sugestao.vendedor_id
            : null;
    const chosen = sellers.find((seller) => String(seller.id) === choice);
    const active = sellers.filter((seller) => seller.ativo);
    const inactive = sellers.filter((seller) => !seller.ativo);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data =
            choice === NEW_SELLER
                ? { acao: 'criar', nome: newName }
                : { acao: 'associar', vendedor_id: Number(choice) };

        router.put(updateUrl, data, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: onClose,
        });
    }

    function option(seller: Seller) {
        const blocked = !seller.ativo && seller.bloqueio_reativacao !== null;

        return (
            <label
                className={`adm-option${choice === String(seller.id) ? ' selected' : ''}${blocked ? ' disabled' : ''}`}
                key={seller.id}
            >
                <input
                    checked={choice === String(seller.id)}
                    disabled={blocked}
                    name="vendedor"
                    onChange={() => setChoice(String(seller.id))}
                    type="radio"
                />
                <span>
                    <strong>
                        {seller.nome}
                        {seller.id === suggestedId && (
                            <span className="es-pill purple">sugerido</span>
                        )}
                    </strong>
                    <small>
                        {blocked
                            ? `Não pode ser reativado: ${seller.bloqueio_reativacao}`
                            : !seller.ativo
                              ? 'Inativo: será reativado ao salvar'
                              : seller.tem_conta
                                ? 'Ativo, com conta de acesso'
                                : 'Ativo, ainda sem conta de acesso'}
                    </small>
                </span>
            </label>
        );
    }

    const submitLabel =
        choice === NEW_SELLER
            ? 'Criar e associar'
            : chosen && !chosen.ativo
              ? 'Reativar e associar'
              : 'Associar';

    return (
        <RecordModal
            className="adm-modal"
            onClose={onClose}
            onSubmit={submit}
            processing={saving}
            submitDisabled={
                choice === '' ||
                (choice === NEW_SELLER && newName.trim() === '')
            }
            submitLabel={submitLabel}
            title={`Associar ${attendant.nome}`}
        >
            <p className="adm-span es-sub">
                Os leads atendidos por esta pessoa na SellFlux serão vinculados
                ao vendedor escolhido.
            </p>
            {active.length > 0 && (
                <>
                    <p className="adm-section-title">Vendedores ativos</p>
                    <div className="adm-span adm-options">
                        {active.map(option)}
                    </div>
                </>
            )}
            {inactive.length > 0 && (
                <>
                    <p className="adm-section-title">Vendedores inativos</p>
                    <div className="adm-span adm-options">
                        {inactive.map(option)}
                    </div>
                </>
            )}
            <p className="adm-section-title">Ou</p>
            <div className="adm-span adm-options">
                <label
                    className={`adm-option${choice === NEW_SELLER ? ' selected' : ''}`}
                >
                    <input
                        checked={choice === NEW_SELLER}
                        name="vendedor"
                        onChange={() => setChoice(NEW_SELLER)}
                        type="radio"
                    />
                    <span>
                        <strong>
                            Criar vendedor novo
                            {attendant.sugestao?.tipo === 'criar' && (
                                <span className="es-pill purple">sugerido</span>
                            )}
                        </strong>
                        <small>
                            Nasce sem conta; uma conta pode ser vinculada depois
                            em Usuários.
                        </small>
                    </span>
                </label>
            </div>
            {choice === NEW_SELLER && (
                <label className="adm-span">
                    Nome do novo vendedor
                    <input
                        autoFocus
                        maxLength={255}
                        onChange={(event) => setNewName(event.target.value)}
                        value={newName}
                    />
                </label>
            )}
            {(errors?.vendedor_id || errors?.nome) && (
                <span className="adm-span field-error">
                    {errors.vendedor_id ?? errors.nome}
                </span>
            )}
        </RecordModal>
    );
}

export default function SellfluxAtendentes({
    unit,
    semVendedorAtivo,
    sellers,
    atendentes,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const counts = {
        pendente: atendentes.filter((item) => item.situacao === 'pendente')
            .length,
        associado: atendentes.filter((item) => item.situacao === 'associado')
            .length,
        ignorado: atendentes.filter((item) => item.situacao === 'ignorado')
            .length,
        todos: atendentes.length,
    };
    const [tab, setTab] = useState<Situation | 'todos'>(
        counts.pendente > 0 ? 'pendente' : 'todos',
    );
    const [syncing, setSyncing] = useState(false);
    const [associating, setAssociating] = useState<Attendant | null>(null);
    const [undoing, setUndoing] = useState<Attendant | null>(null);
    const [busyId, setBusyId] = useState<number | null>(null);
    const visible =
        tab === 'todos'
            ? atendentes
            : atendentes.filter((item) => item.situacao === tab);
    const readOnly = !unit.ativo;
    const updateUrl = (attendant: Attendant) =>
        `/admin/units/${unit.id}/atendentes/${attendant.id}`;

    function sync() {
        router.post(
            `/admin/units/${unit.id}/atendentes/sync`,
            {},
            {
                preserveScroll: true,
                onStart: () => setSyncing(true),
                onFinish: () => setSyncing(false),
            },
        );
    }

    function send(attendant: Attendant, acao: string) {
        router.put(
            updateUrl(attendant),
            { acao },
            {
                preserveScroll: true,
                onStart: () => setBusyId(attendant.id),
                onFinish: () => {
                    setBusyId(null);
                    setUndoing(null);
                },
            },
        );
    }

    return (
        <VisitLayout
            active="admin-units"
            auth={auth}
            selectedUnitId={null}
            title={`Atendentes: ${unit.nome}`}
            units={[]}
        >
            <Link className="adm-back" href="/admin/units">
                <i className="fas fa-arrow-left" aria-hidden="true" /> Unidades
            </Link>
            <div className="es-header">
                <div>
                    <h1>Atendentes da SellFlux</h1>
                    <div className="es-sub">
                        {unit.nome} · ligue quem atende na SellFlux aos
                        vendedores do sistema
                    </div>
                </div>
                <div className="es-header-actions">
                    <button
                        className="btn btn-primary"
                        disabled={
                            syncing || readOnly || !unit.sellflux_configurado
                        }
                        onClick={sync}
                        type="button"
                    >
                        <i
                            className={`fas fa-rotate${syncing ? ' fa-spin' : ''}`}
                            aria-hidden="true"
                        />{' '}
                        {syncing
                            ? 'Buscando na SellFlux...'
                            : 'Atualizar lista'}
                    </button>
                </div>
            </div>

            {semVendedorAtivo && (
                <div className="visit-notice warning">
                    Esta unidade não tem vendedor ativo. Ao associar, reative um
                    vendedor inativo ou crie um novo; sem isso, os leads não têm
                    a quem ser vinculados.
                </div>
            )}
            {readOnly && (
                <div className="visit-notice warning">
                    Unidade arquivada: a lista pode ser consultada, mas não
                    alterada.
                </div>
            )}

            <div className="es-grid adm-stats">
                <div className="es-stat">
                    <small>Pendentes</small>
                    <strong>{counts.pendente}</strong>
                </div>
                <div className="es-stat">
                    <small>Associados</small>
                    <strong>{counts.associado}</strong>
                </div>
                <div className="es-stat">
                    <small>Ignorados</small>
                    <strong>{counts.ignorado}</strong>
                </div>
                <div className="es-stat">
                    <small>Vendedores ativos</small>
                    <strong>
                        {sellers.filter((seller) => seller.ativo).length}
                    </strong>
                </div>
            </div>

            <section className="es-panel">
                <div className="adm-tabs" role="tablist">
                    {tabs.map((item) => (
                        <button
                            aria-selected={tab === item.key}
                            className={tab === item.key ? 'active' : ''}
                            key={item.key}
                            onClick={() => setTab(item.key)}
                            role="tab"
                            type="button"
                        >
                            {item.label}
                            <span className="adm-badge muted">
                                {counts[item.key]}
                            </span>
                        </button>
                    ))}
                </div>

                {atendentes.length === 0 ? (
                    <div className="es-empty">
                        <i
                            className="fas fa-headset adm-empty-icon"
                            aria-hidden="true"
                        />
                        <p>Nenhum atendente na lista ainda.</p>
                        <p className="es-sub">
                            Clique em Atualizar lista para buscar quem atende
                            nos chats desta unidade.
                        </p>
                    </div>
                ) : visible.length === 0 ? (
                    <div className="es-empty">
                        {tab === 'pendente'
                            ? 'Nenhum atendente pendente. Tudo associado ou ignorado.'
                            : 'Nenhum atendente nesta aba.'}
                    </div>
                ) : (
                    <div className="es-table-wrap">
                        <table className="es-table adm-table">
                            <thead>
                                <tr>
                                    <th>Atendente</th>
                                    <th>Situação</th>
                                    <th>Vendedor no sistema</th>
                                    <th aria-label="Ações" />
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((attendant) => (
                                    <tr key={attendant.id}>
                                        <td>
                                            <div className="adm-person">
                                                <span className="adm-avatar">
                                                    {attendant.nome
                                                        .charAt(0)
                                                        .toUpperCase()}
                                                </span>
                                                <div>
                                                    <strong>
                                                        {attendant.nome}
                                                    </strong>
                                                    {attendant.visto_em && (
                                                        <div className="es-sub">
                                                            Visto em{' '}
                                                            {attendant.visto_em}
                                                        </div>
                                                    )}
                                                    <SuggestionNote
                                                        attendant={attendant}
                                                        sellers={sellers}
                                                    />
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Situação">
                                            <span
                                                className={`es-pill ${attendant.situacao === 'associado' ? 'green' : attendant.situacao === 'pendente' ? 'yellow' : 'slate'}`}
                                            >
                                                {attendant.situacao ===
                                                'associado'
                                                    ? 'Associado'
                                                    : attendant.situacao ===
                                                        'pendente'
                                                      ? 'Pendente'
                                                      : 'Ignorado'}
                                            </span>
                                        </td>
                                        <td data-label="Vendedor">
                                            {attendant.vendedor_nome ? (
                                                <>
                                                    <strong>
                                                        {
                                                            attendant.vendedor_nome
                                                        }
                                                    </strong>
                                                    {attendant.vendedor_ativo ===
                                                        false && (
                                                        <div className="es-sub adm-warn-text">
                                                            Vendedor inativo:
                                                            não recebe leads até
                                                            ser reativado ou
                                                            trocado.
                                                        </div>
                                                    )}
                                                </>
                                            ) : (
                                                <span className="es-sub">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td>
                                            <div className="adm-row-actions">
                                                <button
                                                    className={`adm-action${attendant.situacao === 'pendente' ? ' primary' : ''}`}
                                                    disabled={
                                                        readOnly ||
                                                        busyId === attendant.id
                                                    }
                                                    onClick={() =>
                                                        setAssociating(
                                                            attendant,
                                                        )
                                                    }
                                                    type="button"
                                                >
                                                    <i
                                                        className="fas fa-link"
                                                        aria-hidden="true"
                                                    />
                                                    {attendant.situacao ===
                                                    'associado'
                                                        ? 'Trocar'
                                                        : 'Associar'}
                                                </button>
                                                {attendant.situacao ===
                                                    'pendente' && (
                                                    <button
                                                        className="adm-action"
                                                        disabled={
                                                            readOnly ||
                                                            busyId ===
                                                                attendant.id
                                                        }
                                                        onClick={() =>
                                                            send(
                                                                attendant,
                                                                'ignorar',
                                                            )
                                                        }
                                                        title="Para quem não é vendedor, como coordenação ou recepção"
                                                        type="button"
                                                    >
                                                        <i
                                                            className="fas fa-eye-slash"
                                                            aria-hidden="true"
                                                        />
                                                        Ignorar
                                                    </button>
                                                )}
                                                {attendant.situacao !==
                                                    'pendente' && (
                                                    <button
                                                        className="adm-action"
                                                        disabled={
                                                            readOnly ||
                                                            busyId ===
                                                                attendant.id
                                                        }
                                                        onClick={() =>
                                                            attendant.situacao ===
                                                            'associado'
                                                                ? setUndoing(
                                                                      attendant,
                                                                  )
                                                                : send(
                                                                      attendant,
                                                                      'desfazer',
                                                                  )
                                                        }
                                                        type="button"
                                                    >
                                                        <i
                                                            className="fas fa-rotate-left"
                                                            aria-hidden="true"
                                                        />
                                                        {attendant.situacao ===
                                                        'associado'
                                                            ? 'Desassociar'
                                                            : 'Voltar a pendente'}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {associating && (
                <AssociateModal
                    attendant={associating}
                    key={associating.id}
                    onClose={() => setAssociating(null)}
                    sellers={sellers}
                    updateUrl={updateUrl(associating)}
                />
            )}

            {undoing && (
                <ConfirmDialog
                    confirmLabel="Desassociar"
                    onClose={() => setUndoing(null)}
                    onConfirm={() => send(undoing, 'desfazer')}
                    processing={busyId === undoing.id}
                    title={`Desassociar ${undoing.nome}`}
                    tone="danger"
                >
                    <p>
                        {undoing.nome} volta a pendente e deixa de ligar leads a{' '}
                        <strong>{undoing.vendedor_nome}</strong>. Os leads já
                        vinculados continuam com esse vendedor.
                    </p>
                </ConfirmDialog>
            )}
        </VisitLayout>
    );
}
