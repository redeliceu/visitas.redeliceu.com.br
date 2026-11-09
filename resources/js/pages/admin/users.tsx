import { router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import ConfirmDialog from '@/components/visitas/ConfirmDialog';
import RecordModal from '@/components/visitas/RecordModal';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Role = { value: string; label: string };
type Account = {
    id: number;
    name: string;
    email: string;
    role: string;
    ativo: boolean;
    unidades: Unit[];
    vendedores: Array<{ id: number; unidade_id: number; nome: string }>;
};
type UnlinkedSeller = {
    id: number;
    unidade_id: number;
    unidade: string | null;
    nome: string;
    ativo: boolean;
    leads_count: number;
};
type Props = {
    users: Account[];
    units: Unit[];
    roles: Role[];
    unlinkedSellers: UnlinkedSeller[];
};

const roleHelp: Record<string, string> = {
    master: 'Acesso total, inclusive usuários, unidades e integrações.',
    gestor: 'Vê e gerencia tudo das unidades atribuídas.',
    atendimento:
        'Trabalha só os próprios leads e a fila das unidades atribuídas.',
};

const emptyForm = {
    name: '',
    email: '',
    password: '',
    role: 'atendimento',
    ativo: true,
    unit_ids: [] as number[],
};

export default function Users({ users, units, roles, unlinkedSellers }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Account | null>(null);
    const [showPassword, setShowPassword] = useState(false);
    const [linking, setLinking] = useState<Account | null>(null);
    const [confirmLink, setConfirmLink] = useState(false);
    const [toggling, setToggling] = useState<Account | null>(null);
    const [search, setSearch] = useState('');
    const [roleFilter, setRoleFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const form = useForm(emptyForm);
    const linkForm = useForm({ vendedor_id: '' });

    const roleLabel = (value: string) =>
        roles.find((role) => role.value === value)?.label ?? value;

    const visibleUsers = useMemo(() => {
        const term = search.trim().toLowerCase();

        return users.filter(
            (user) =>
                (term === '' ||
                    user.name.toLowerCase().includes(term) ||
                    user.email.toLowerCase().includes(term)) &&
                (roleFilter === '' || user.role === roleFilter) &&
                (statusFilter === '' ||
                    (statusFilter === 'ativo') === user.ativo),
        );
    }, [users, search, roleFilter, statusFilter]);

    const linkableSellers = linking
        ? unlinkedSellers.filter((seller) =>
              linking.unidades.some((unit) => unit.id === seller.unidade_id),
          )
        : [];
    const chosenSeller = unlinkedSellers.find(
        (seller) => String(seller.id) === linkForm.data.vendedor_id,
    );

    function openCreate() {
        setEditing(null);
        form.setData({ ...emptyForm });
        form.clearErrors();
        setShowPassword(false);
        setModalOpen(true);
    }

    function openEdit(user: Account) {
        setEditing(user);
        form.clearErrors();
        form.setData({
            name: user.name,
            email: user.email,
            password: '',
            role: user.role,
            ativo: user.ativo,
            unit_ids: user.unidades.map((unit) => unit.id),
        });
        setShowPassword(false);
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
            form.put(`/admin/users/${editing.id}`, options);
        } else {
            form.post('/admin/users', options);
        }
    }

    function setRole(role: string) {
        form.setData((current) => ({
            ...current,
            role,
            unit_ids: role === 'master' ? [] : current.unit_ids,
        }));
    }

    function toggleUnit(unitId: number) {
        form.setData((current) => ({
            ...current,
            unit_ids: current.unit_ids.includes(unitId)
                ? current.unit_ids.filter((id) => id !== unitId)
                : [...current.unit_ids, unitId],
        }));
    }

    function openLink(user: Account) {
        setLinking(user);
        linkForm.setData('vendedor_id', '');
        linkForm.clearErrors();
    }

    function submitLink() {
        if (!linking) {
            return;
        }

        linkForm.post(`/admin/users/${linking.id}/sellers`, {
            preserveScroll: true,
            onSuccess: () => {
                setConfirmLink(false);
                setLinking(null);
            },
            onError: () => setConfirmLink(false),
        });
    }

    function toggleActive() {
        if (!toggling) {
            return;
        }

        router.patch(
            `/admin/users/${toggling.id}/active`,
            { ativo: !toggling.ativo },
            { preserveScroll: true, onFinish: () => setToggling(null) },
        );
    }

    return (
        <VisitLayout
            active="admin-users"
            auth={auth}
            selectedUnitId={null}
            title="Usuários"
            units={[]}
        >
            <div className="es-header">
                <div>
                    <h1>Usuários</h1>
                    <div className="es-sub">
                        Contas de acesso, perfis e unidades de cada pessoa
                    </div>
                </div>
                <div className="es-header-actions">
                    <button
                        className="btn btn-primary"
                        onClick={openCreate}
                        type="button"
                    >
                        <i className="fas fa-user-plus" aria-hidden="true" />{' '}
                        Novo usuário
                    </button>
                </div>
            </div>

            <div className="es-grid adm-stats">
                <div className="es-stat">
                    <small>Contas ativas</small>
                    <strong>{users.filter((user) => user.ativo).length}</strong>
                </div>
                <div className="es-stat">
                    <small>Gestores</small>
                    <strong>
                        {users.filter((user) => user.role === 'gestor').length}
                    </strong>
                </div>
                <div className="es-stat">
                    <small>Atendimento / Vendedor</small>
                    <strong>
                        {
                            users.filter((user) => user.role === 'atendimento')
                                .length
                        }
                    </strong>
                </div>
                <div className="es-stat">
                    <small>Vendedores sem conta</small>
                    <strong>{unlinkedSellers.length}</strong>
                </div>
            </div>

            <section className="es-panel">
                <div className="adm-toolbar">
                    <label className="adm-search">
                        <i className="fas fa-search" aria-hidden="true" />
                        <input
                            aria-label="Buscar por nome ou e-mail"
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar por nome ou e-mail"
                            type="search"
                            value={search}
                        />
                    </label>
                    <select
                        aria-label="Filtrar por perfil"
                        onChange={(event) => setRoleFilter(event.target.value)}
                        value={roleFilter}
                    >
                        <option value="">Todos os perfis</option>
                        {roles.map((role) => (
                            <option key={role.value} value={role.value}>
                                {role.label}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filtrar por situação"
                        onChange={(event) =>
                            setStatusFilter(event.target.value)
                        }
                        value={statusFilter}
                    >
                        <option value="">Ativos e inativos</option>
                        <option value="ativo">Só ativos</option>
                        <option value="inativo">Só inativos</option>
                    </select>
                </div>

                <div className="es-table-wrap">
                    <table className="es-table adm-table">
                        <thead>
                            <tr>
                                <th>Pessoa</th>
                                <th>Perfil</th>
                                <th>Unidades</th>
                                <th>Situação</th>
                                <th aria-label="Ações" />
                            </tr>
                        </thead>
                        <tbody>
                            {visibleUsers.map((user) => (
                                <tr
                                    className={user.ativo ? '' : 'adm-muted'}
                                    key={user.id}
                                >
                                    <td>
                                        <div className="adm-person">
                                            <span className="adm-avatar">
                                                {user.name
                                                    .charAt(0)
                                                    .toUpperCase()}
                                            </span>
                                            <div>
                                                <strong>{user.name}</strong>
                                                <div className="es-sub">
                                                    {user.email}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Perfil">
                                        <span
                                            className={`es-pill ${user.role === 'master' ? 'purple' : user.role === 'gestor' ? 'blue' : 'slate'}`}
                                        >
                                            {roleLabel(user.role)}
                                        </span>
                                        {user.role === 'atendimento' && (
                                            <div className="es-sub">
                                                {user.vendedores.length === 1
                                                    ? '1 vendedor vinculado'
                                                    : `${user.vendedores.length} vendedores vinculados`}
                                            </div>
                                        )}
                                    </td>
                                    <td data-label="Unidades">
                                        {user.role === 'master' ? (
                                            <span className="es-sub">
                                                Todas
                                            </span>
                                        ) : user.unidades.length === 0 ? (
                                            <span className="adm-warn-text">
                                                Nenhuma unidade
                                            </span>
                                        ) : (
                                            <div className="adm-chips">
                                                {user.unidades.map((unit) => (
                                                    <span
                                                        className="adm-chip"
                                                        key={unit.id}
                                                    >
                                                        {unit.nome}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td data-label="Situação">
                                        <span
                                            className={`es-pill ${user.ativo ? 'green' : 'red'}`}
                                        >
                                            {user.ativo ? 'Ativa' : 'Inativa'}
                                        </span>
                                    </td>
                                    <td>
                                        <div className="adm-row-actions">
                                            <button
                                                className="adm-action"
                                                onClick={() => openEdit(user)}
                                                type="button"
                                            >
                                                <i
                                                    className="fas fa-pen"
                                                    aria-hidden="true"
                                                />
                                                Editar
                                            </button>
                                            {user.role === 'atendimento' && (
                                                <button
                                                    className="adm-action"
                                                    onClick={() =>
                                                        openLink(user)
                                                    }
                                                    type="button"
                                                >
                                                    <i
                                                        className="fas fa-link"
                                                        aria-hidden="true"
                                                    />
                                                    Vendedores
                                                </button>
                                            )}
                                            {user.id !== auth.user?.id && (
                                                <button
                                                    className={`adm-action${user.ativo ? ' danger' : ''}`}
                                                    onClick={() =>
                                                        setToggling(user)
                                                    }
                                                    type="button"
                                                >
                                                    <i
                                                        className={`fas ${user.ativo ? 'fa-user-slash' : 'fa-user-check'}`}
                                                        aria-hidden="true"
                                                    />
                                                    {user.ativo
                                                        ? 'Desativar'
                                                        : 'Ativar'}
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {visibleUsers.length === 0 && (
                                <tr>
                                    <td className="es-empty" colSpan={5}>
                                        Nenhuma conta encontrada com esses
                                        filtros.
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
                    submitLabel={editing ? 'Salvar alterações' : 'Criar conta'}
                    title={
                        editing
                            ? `Editar usuário: ${editing.name}`
                            : 'Novo usuário'
                    }
                >
                    <p className="adm-section-title">Dados de acesso</p>
                    <label>
                        Nome
                        <input
                            autoFocus
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                            value={form.data.name}
                        />
                        {form.errors.name && (
                            <span className="field-error">
                                {form.errors.name}
                            </span>
                        )}
                    </label>
                    <label>
                        E-mail
                        <input
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                            required
                            type="email"
                            value={form.data.email}
                        />
                        {form.errors.email && (
                            <span className="field-error">
                                {form.errors.email}
                            </span>
                        )}
                    </label>
                    <label className="adm-span">
                        {editing ? 'Nova senha' : 'Senha'}
                        <span className="adm-input-group">
                            <input
                                autoComplete="new-password"
                                minLength={12}
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                placeholder={
                                    editing
                                        ? 'Deixe em branco para manter a atual'
                                        : 'Mínimo de 12 caracteres'
                                }
                                required={!editing}
                                type={showPassword ? 'text' : 'password'}
                                value={form.data.password}
                            />
                            <button
                                aria-label={
                                    showPassword
                                        ? 'Ocultar senha'
                                        : 'Mostrar senha'
                                }
                                className="adm-input-btn"
                                onClick={() => setShowPassword((on) => !on)}
                                type="button"
                            >
                                <i
                                    className={`fas ${showPassword ? 'fa-eye-slash' : 'fa-eye'}`}
                                    aria-hidden="true"
                                />
                            </button>
                        </span>
                        {form.errors.password && (
                            <span className="field-error">
                                {form.errors.password}
                            </span>
                        )}
                    </label>

                    <p className="adm-section-title">Perfil</p>
                    <div
                        aria-label="Perfil"
                        className="adm-span adm-options"
                        role="radiogroup"
                    >
                        {roles.map((role) => (
                            <label
                                className={`adm-option${form.data.role === role.value ? ' selected' : ''}`}
                                key={role.value}
                            >
                                <input
                                    checked={form.data.role === role.value}
                                    name="role"
                                    onChange={() => setRole(role.value)}
                                    type="radio"
                                    value={role.value}
                                />
                                <span>
                                    <strong>{role.label}</strong>
                                    <small>{roleHelp[role.value]}</small>
                                </span>
                            </label>
                        ))}
                        {form.errors.role && (
                            <span className="field-error">
                                {form.errors.role}
                            </span>
                        )}
                    </div>

                    {form.data.role !== 'master' && (
                        <>
                            <p className="adm-section-title">
                                Unidades atribuídas
                                <button
                                    className="adm-link"
                                    onClick={() =>
                                        form.setData(
                                            'unit_ids',
                                            form.data.unit_ids.length ===
                                                units.length
                                                ? []
                                                : units.map((unit) => unit.id),
                                        )
                                    }
                                    type="button"
                                >
                                    {form.data.unit_ids.length === units.length
                                        ? 'Limpar'
                                        : 'Selecionar todas'}
                                </button>
                            </p>
                            <div className="adm-span adm-chips">
                                {units.map((unit) => {
                                    const on = form.data.unit_ids.includes(
                                        unit.id,
                                    );

                                    return (
                                        <button
                                            aria-pressed={on}
                                            className={`adm-chip toggle${on ? ' on' : ''}`}
                                            key={unit.id}
                                            onClick={() => toggleUnit(unit.id)}
                                            type="button"
                                        >
                                            {on && (
                                                <i
                                                    className="fas fa-check"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {unit.nome}
                                        </button>
                                    );
                                })}
                                {form.errors.unit_ids && (
                                    <span className="field-error">
                                        {form.errors.unit_ids}
                                    </span>
                                )}
                            </div>
                        </>
                    )}

                    <label className="adm-span adm-switch">
                        <input
                            checked={form.data.ativo}
                            onChange={(event) =>
                                form.setData('ativo', event.target.checked)
                            }
                            type="checkbox"
                        />
                        <span>
                            <strong>Conta ativa</strong>
                            <small>
                                Contas inativas não conseguem entrar no sistema.
                            </small>
                        </span>
                    </label>
                </RecordModal>
            )}

            {linking && !confirmLink && (
                <RecordModal
                    className="adm-modal"
                    onClose={() => setLinking(null)}
                    onSubmit={(event) => {
                        event.preventDefault();
                        setConfirmLink(true);
                    }}
                    processing={linkForm.processing}
                    submitDisabled={linkForm.data.vendedor_id === ''}
                    submitLabel="Continuar"
                    title={`Vendedores de ${linking.name}`}
                >
                    <p className="adm-section-title">Vinculados a esta conta</p>
                    <div className="adm-span adm-chips">
                        {linking.vendedores.length === 0 ? (
                            <span className="es-sub">
                                Nenhum vendedor vinculado.
                            </span>
                        ) : (
                            linking.vendedores.map((seller) => (
                                <span className="adm-chip" key={seller.id}>
                                    {seller.nome} ·{' '}
                                    {units.find(
                                        (unit) => unit.id === seller.unidade_id,
                                    )?.nome ?? 'Unidade inativa'}
                                </span>
                            ))
                        )}
                    </div>
                    <p className="adm-section-title">
                        Vincular vendedor existente
                    </p>
                    <p className="adm-span es-sub">
                        Use para ligar a conta a um vendedor cadastrado sem
                        conta, por exemplo da importação histórica. Os leads
                        dele passam a aparecer para esta conta.
                    </p>
                    {linkableSellers.length === 0 ? (
                        <p className="adm-span es-empty">
                            Não há vendedores sem conta nas unidades desta
                            pessoa.
                        </p>
                    ) : (
                        <div
                            aria-label="Vendedor sem conta"
                            className="adm-span adm-options"
                            role="radiogroup"
                        >
                            {linkableSellers.map((seller) => (
                                <label
                                    className={`adm-option${linkForm.data.vendedor_id === String(seller.id) ? ' selected' : ''}`}
                                    key={seller.id}
                                >
                                    <input
                                        checked={
                                            linkForm.data.vendedor_id ===
                                            String(seller.id)
                                        }
                                        name="vendedor_id"
                                        onChange={() =>
                                            linkForm.setData(
                                                'vendedor_id',
                                                String(seller.id),
                                            )
                                        }
                                        required
                                        type="radio"
                                    />
                                    <span>
                                        <strong>
                                            {seller.nome}
                                            {!seller.ativo && (
                                                <span className="es-pill red">
                                                    inativo
                                                </span>
                                            )}
                                        </strong>
                                        <small>
                                            {seller.unidade} ·{' '}
                                            {seller.leads_count === 1
                                                ? '1 lead'
                                                : `${seller.leads_count} leads`}
                                        </small>
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}
                    {linkForm.errors.vendedor_id && (
                        <span className="field-error adm-span">
                            {linkForm.errors.vendedor_id}
                        </span>
                    )}
                </RecordModal>
            )}

            {linking && confirmLink && chosenSeller && (
                <ConfirmDialog
                    confirmLabel="Vincular e mesclar"
                    onClose={() => setConfirmLink(false)}
                    onConfirm={submitLink}
                    processing={linkForm.processing}
                    title="Confirmar vínculo"
                >
                    <p>
                        Vincular <strong>{chosenSeller.nome}</strong> (
                        {chosenSeller.unidade}) à conta de{' '}
                        <strong>{linking.name}</strong>?
                    </p>
                    <ul>
                        <li>
                            {chosenSeller.leads_count === 0
                                ? 'Ele ainda não tem leads; os próximos aparecem para esta conta.'
                                : chosenSeller.leads_count === 1
                                  ? 'O lead dele passa a aparecer para esta conta.'
                                  : `Os ${chosenSeller.leads_count} leads dele passam a aparecer para esta conta.`}
                        </li>
                        <li>
                            Se a conta já tiver um vendedor nesta unidade, os
                            leads, agendamentos, visitas e metas dele são
                            mesclados no vendedor escolhido, e o cadastro antigo
                            é desativado.
                        </li>
                        <li>Nada é apagado.</li>
                    </ul>
                </ConfirmDialog>
            )}

            {toggling && (
                <ConfirmDialog
                    confirmLabel={toggling.ativo ? 'Desativar' : 'Ativar'}
                    onClose={() => setToggling(null)}
                    onConfirm={toggleActive}
                    title={toggling.ativo ? 'Desativar conta' : 'Ativar conta'}
                    tone={toggling.ativo ? 'danger' : 'primary'}
                >
                    <p>
                        {toggling.ativo
                            ? `${toggling.name} não vai mais conseguir entrar no sistema. Os leads e registros dela continuam guardados, e você pode reativar a conta depois.`
                            : `${toggling.name} volta a ter acesso com o perfil e as unidades atuais.`}
                    </p>
                </ConfirmDialog>
            )}
        </VisitLayout>
    );
}
