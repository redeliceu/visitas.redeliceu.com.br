import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

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

export default function Users({ users, units, roles, unlinkedSellers }: Props) {
    const page = usePage<{ flash?: { success?: string | null } }>();
    const [editing, setEditing] = useState<Account | null>(null);
    const linkForm = useForm({ vendedor_id: '' });

    function linkSeller(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!editing) {
            return;
        }

        const seller = unlinkedSellers.find(
            (candidate) => String(candidate.id) === linkForm.data.vendedor_id,
        );
        const confirmed = window.confirm(
            `Vincular "${seller?.nome ?? ''}" à conta de ${editing.name}? ` +
                'Se a conta já tiver um vendedor nesta unidade, os leads, ' +
                'agendamentos, visitas e metas dele serão mesclados no ' +
                'vendedor escolhido, e o cadastro antigo será desativado.',
        );

        if (!confirmed) {
            return;
        }

        linkForm.post(`/admin/users/${editing.id}/sellers`, {
            preserveScroll: true,
            onSuccess: resetForm,
        });
    }
    const form = useForm({
        name: '',
        email: '',
        password: '',
        role: 'atendimento',
        ativo: true,
        unit_ids: [] as number[],
    });

    function resetForm() {
        setEditing(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            email: '',
            password: '',
            role: 'atendimento',
            ativo: true,
            unit_ids: [],
        });
    }

    function edit(user: Account) {
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
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = { onSuccess: resetForm };

        if (editing) {
            form.put(`/admin/users/${editing.id}`, options);
        } else {
            form.post('/admin/users', options);
        }
    }

    function toggleUnit(unitId: number, checked: boolean) {
        const unitIds = checked
            ? [...form.data.unit_ids, unitId]
            : form.data.unit_ids.filter((id) => id !== unitId);
        form.setData((current) => ({
            ...current,
            unit_ids: unitIds,
        }));
    }

    return (
        <>
            <Head title="Usuários" />
            <main className="min-h-screen bg-slate-100">
                <header className="flex items-center justify-between bg-blue-900 px-6 py-4 text-white">
                    <div>
                        <h1 className="text-xl font-semibold">Usuários</h1>
                        <a className="text-sm text-blue-100 underline" href="/">
                            Voltar ao início
                        </a>
                    </div>
                    <button
                        className="rounded border border-white/50 px-3 py-2 text-sm"
                        onClick={() => router.post('/logout')}
                        type="button"
                    >
                        Sair
                    </button>
                </header>
                {page.props.flash?.success && (
                    <div className="mx-auto mt-6 max-w-6xl px-6">
                        <p
                            className="rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800"
                            role="status"
                        >
                            {page.props.flash.success}
                        </p>
                    </div>
                )}
                <div className="mx-auto grid max-w-6xl gap-6 px-6 py-8 lg:grid-cols-[minmax(0,2fr)_minmax(300px,1fr)]">
                    <section className="overflow-x-auto rounded-lg bg-white p-5 shadow-sm">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900">
                            Contas cadastradas
                        </h2>
                        <table className="w-full min-w-[620px] text-left text-sm">
                            <thead className="border-b text-slate-600">
                                <tr>
                                    <th className="px-2 py-3">Nome</th>
                                    <th className="px-2 py-3">Perfil</th>
                                    <th className="px-2 py-3">Unidades</th>
                                    <th className="px-2 py-3">Status</th>
                                    <th className="px-2 py-3">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <tr className="border-b" key={user.id}>
                                        <td className="px-2 py-3">
                                            <strong>{user.name}</strong>
                                            <div className="text-slate-500">
                                                {user.email}
                                            </div>
                                            {user.role === 'atendimento' && (
                                                <div className="text-slate-500">
                                                    Vendedores vinculados:{' '}
                                                    {user.vendedores.length}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-2 py-3">
                                            {roles.find(
                                                (role) =>
                                                    role.value === user.role,
                                            )?.label ?? user.role}
                                        </td>
                                        <td className="px-2 py-3">
                                            {user.role === 'master'
                                                ? 'Todas'
                                                : user.unidades
                                                      .map((unit) => unit.nome)
                                                      .join(', ')}
                                        </td>
                                        <td className="px-2 py-3">
                                            {user.ativo ? 'Ativo' : 'Inativo'}
                                        </td>
                                        <td className="px-2 py-3">
                                            <div className="flex gap-2">
                                                <button
                                                    className="text-blue-800 underline"
                                                    onClick={() => edit(user)}
                                                    type="button"
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    className="text-blue-800 underline"
                                                    onClick={() =>
                                                        router.patch(
                                                            `/admin/users/${user.id}/active`,
                                                            {
                                                                ativo: !user.ativo,
                                                            },
                                                        )
                                                    }
                                                    type="button"
                                                >
                                                    {user.ativo
                                                        ? 'Desativar'
                                                        : 'Ativar'}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                    <form
                        className="space-y-4 rounded-lg bg-white p-5 shadow-sm"
                        onSubmit={submit}
                    >
                        <h2 className="text-lg font-semibold text-slate-900">
                            {editing ? 'Editar usuário' : 'Criar usuário'}
                        </h2>
                        <label className="block text-sm font-medium text-slate-700">
                            Nome
                            <input
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                value={form.data.name}
                                required
                            />
                            {form.errors.name && (
                                <span className="mt-1 block text-sm text-red-700">
                                    {form.errors.name}
                                </span>
                            )}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            E-mail
                            <input
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                type="email"
                                value={form.data.email}
                                required
                            />
                            {form.errors.email && (
                                <span className="mt-1 block text-sm text-red-700">
                                    {form.errors.email}
                                </span>
                            )}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            {editing
                                ? 'Nova senha (opcional)'
                                : 'Senha (mínimo 12 caracteres)'}
                            <input
                                autoComplete="new-password"
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                type="password"
                                value={form.data.password}
                                required={!editing}
                            />
                            {form.errors.password && (
                                <span className="mt-1 block text-sm text-red-700">
                                    {form.errors.password}
                                </span>
                            )}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Perfil
                            <select
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                onChange={(event) => {
                                    const role = event.target.value;
                                    form.setData('role', role);
                                    if (role === 'master') {
                                        form.setData('unit_ids', []);
                                    }
                                }}
                                value={form.data.role}
                            >
                                {roles.map((role) => (
                                    <option key={role.value} value={role.value}>
                                        {role.label}
                                    </option>
                                ))}
                            </select>
                            {form.errors.role && (
                                <span className="mt-1 block text-sm text-red-700">
                                    {form.errors.role}
                                </span>
                            )}
                        </label>
                        {form.data.role !== 'master' && (
                            <fieldset>
                                <legend className="mb-2 text-sm font-medium text-slate-700">
                                    Unidades atribuídas
                                </legend>
                                <div className="space-y-2">
                                    {units.map((unit) => (
                                        <label
                                            className="flex items-center gap-2 text-sm text-slate-700"
                                            key={unit.id}
                                        >
                                            <input
                                                checked={form.data.unit_ids.includes(
                                                    unit.id,
                                                )}
                                                onChange={(event) =>
                                                    toggleUnit(
                                                        unit.id,
                                                        event.target.checked,
                                                    )
                                                }
                                                type="checkbox"
                                            />
                                            {unit.nome}
                                        </label>
                                    ))}
                                </div>
                                {form.errors.unit_ids && (
                                    <p className="mt-1 text-sm text-red-700">
                                        {form.errors.unit_ids}
                                    </p>
                                )}
                            </fieldset>
                        )}
                        <label className="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                checked={form.data.ativo}
                                onChange={(event) =>
                                    form.setData('ativo', event.target.checked)
                                }
                                type="checkbox"
                            />
                            Conta ativa
                        </label>
                        {editing && editing.role === 'atendimento' && (
                            <fieldset className="space-y-2 rounded border border-slate-200 p-3">
                                <legend className="px-1 text-sm font-medium text-slate-700">
                                    Vendedores desta conta
                                </legend>
                                <ul className="text-sm text-slate-600">
                                    {editing.vendedores.map((seller) => (
                                        <li key={seller.id}>
                                            {seller.nome} •{' '}
                                            {units.find(
                                                (unit) =>
                                                    unit.id ===
                                                    seller.unidade_id,
                                            )?.nome ?? 'Unidade inativa'}
                                        </li>
                                    ))}
                                    {editing.vendedores.length === 0 && (
                                        <li>Nenhum vendedor vinculado.</li>
                                    )}
                                </ul>
                            </fieldset>
                        )}
                        <div className="flex gap-2">
                            <button
                                className="rounded bg-blue-800 px-4 py-2 text-white disabled:opacity-60"
                                disabled={form.processing}
                                type="submit"
                            >
                                {editing ? 'Salvar alterações' : 'Criar conta'}
                            </button>
                            {editing && (
                                <button
                                    className="rounded border border-slate-300 px-4 py-2"
                                    onClick={resetForm}
                                    type="button"
                                >
                                    Cancelar
                                </button>
                            )}
                        </div>
                    </form>
                    {editing && editing.role === 'atendimento' && (
                        <form
                            className="space-y-3 rounded-lg bg-white p-5 shadow-sm lg:col-start-2"
                            onSubmit={linkSeller}
                        >
                            <h2 className="text-lg font-semibold text-slate-900">
                                Vincular vendedor existente
                            </h2>
                            <p className="text-sm text-slate-600">
                                Use para ligar {editing.name} a um vendedor já
                                cadastrado sem conta, por exemplo um vendedor da
                                importação histórica. Os leads desse vendedor
                                passam a aparecer para a conta.
                            </p>
                            <label className="block text-sm font-medium text-slate-700">
                                Vendedor sem conta
                                <select
                                    className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                    onChange={(event) =>
                                        linkForm.setData(
                                            'vendedor_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    value={linkForm.data.vendedor_id}
                                >
                                    <option value="">Selecione</option>
                                    {unlinkedSellers
                                        .filter((seller) =>
                                            editing.unidades.some(
                                                (unit) =>
                                                    unit.id ===
                                                    seller.unidade_id,
                                            ),
                                        )
                                        .map((seller) => (
                                            <option
                                                key={seller.id}
                                                value={seller.id}
                                            >
                                                {seller.nome} • {seller.unidade}{' '}
                                                • {seller.leads_count} leads
                                                {seller.ativo
                                                    ? ''
                                                    : ' (inativo)'}
                                            </option>
                                        ))}
                                </select>
                                {linkForm.errors.vendedor_id && (
                                    <span className="mt-1 block text-sm text-red-700">
                                        {linkForm.errors.vendedor_id}
                                    </span>
                                )}
                            </label>
                            <button
                                className="rounded bg-blue-800 px-4 py-2 text-white disabled:opacity-60"
                                disabled={
                                    linkForm.processing ||
                                    linkForm.data.vendedor_id === ''
                                }
                                type="submit"
                            >
                                Vincular e mesclar
                            </button>
                        </form>
                    )}
                </div>
            </main>
        </>
    );
}
