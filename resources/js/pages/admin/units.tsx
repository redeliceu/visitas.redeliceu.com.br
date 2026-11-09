import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

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
type SharedProps = {
    flash?: { success?: string | null; warning?: string | null };
};

export default function Units({ units }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [editing, setEditing] = useState<Unit | null>(null);
    const [testingId, setTestingId] = useState<number | null>(null);
    const form = useForm({
        nome: '',
        sellflux_token: '',
        remover_sellflux_token: false,
    });

    function edit(unit: Unit) {
        setEditing(unit);
        form.setData({
            nome: unit.nome,
            sellflux_token: '',
            remover_sellflux_token: false,
        });
        form.clearErrors();
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

    function resetForm() {
        setEditing(null);
        form.reset();
        form.clearErrors();
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (editing) {
            form.put(`/admin/units/${editing.id}`, { onSuccess: resetForm });
        } else {
            form.post('/admin/units', { onSuccess: resetForm });
        }
    }

    return (
        <>
            <Head title="Unidades" />
            <main className="min-h-screen bg-slate-100">
                <header className="flex items-center justify-between bg-blue-900 px-6 py-4 text-white">
                    <div>
                        <h1 className="text-xl font-semibold">Unidades</h1>
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
                {flash?.success && (
                    <p className="mx-auto mt-6 max-w-5xl rounded bg-emerald-100 px-4 py-3 text-sm text-emerald-900">
                        {flash.success}
                    </p>
                )}
                {flash?.warning && (
                    <p className="mx-auto mt-6 max-w-5xl rounded bg-amber-100 px-4 py-3 text-sm text-amber-900">
                        {flash.warning}
                    </p>
                )}
                <section className="mx-auto grid max-w-5xl gap-6 px-6 py-8 md:grid-cols-[2fr_1fr]">
                    <div className="rounded-lg bg-white p-5 shadow-sm">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900">
                            Unidades cadastradas
                        </h2>
                        <table className="w-full text-left text-sm">
                            <thead className="border-b text-slate-600">
                                <tr>
                                    <th className="py-3">Unidade</th>
                                    <th className="py-3">Status</th>
                                    <th className="py-3">SellFlux</th>
                                    <th className="py-3">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                {units.map((unit) => (
                                    <tr className="border-b" key={unit.id}>
                                        <td className="py-3">{unit.nome}</td>
                                        <td className="py-3">
                                            {unit.ativo ? 'Ativa' : 'Arquivada'}
                                        </td>
                                        <td className="py-3">
                                            {unit.sellflux_configurado ? (
                                                <>
                                                    <span className="block">
                                                        {unit.sellflux_projeto
                                                            ? `Projeto ${unit.sellflux_projeto}`
                                                            : 'Token configurado'}
                                                    </span>
                                                    {unit.sellflux_atualizado_em && (
                                                        <span className="block text-xs text-slate-500">
                                                            Alterado em{' '}
                                                            {
                                                                unit.sellflux_atualizado_em
                                                            }
                                                        </span>
                                                    )}
                                                </>
                                            ) : (
                                                <span className="text-slate-500">
                                                    Não configurado
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3">
                                            <div className="flex flex-wrap gap-x-3 gap-y-1">
                                                <button
                                                    className="text-blue-800 underline"
                                                    onClick={() => edit(unit)}
                                                    type="button"
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    className="text-blue-800 underline"
                                                    onClick={() =>
                                                        router.patch(
                                                            `/admin/units/${unit.id}/active`,
                                                            {
                                                                ativo: !unit.ativo,
                                                            },
                                                        )
                                                    }
                                                    type="button"
                                                >
                                                    {unit.ativo
                                                        ? 'Arquivar'
                                                        : 'Reativar'}
                                                </button>
                                                {unit.sellflux_configurado && (
                                                    <button
                                                        className="text-blue-800 underline disabled:text-slate-400"
                                                        disabled={
                                                            testingId !== null
                                                        }
                                                        onClick={() =>
                                                            testConnection(unit)
                                                        }
                                                        type="button"
                                                    >
                                                        {testingId === unit.id
                                                            ? 'Testando...'
                                                            : 'Testar conexão'}
                                                    </button>
                                                )}
                                                {unit.sellflux_configurado && (
                                                    <Link
                                                        className="text-blue-800 underline"
                                                        href={`/admin/units/${unit.id}/atendentes`}
                                                    >
                                                        Atendentes
                                                        {unit.atendentes_pendentes >
                                                            0 &&
                                                            ` (${unit.atendentes_pendentes} ${unit.atendentes_pendentes === 1 ? 'pendente' : 'pendentes'})`}
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <form
                        className="space-y-4 rounded-lg bg-white p-5 shadow-sm"
                        onSubmit={submit}
                    >
                        <h2 className="text-lg font-semibold text-slate-900">
                            {editing ? 'Editar unidade' : 'Cadastrar unidade'}
                        </h2>
                        <label className="block text-sm font-medium text-slate-700">
                            Nome
                            <input
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                onChange={(event) =>
                                    form.setData('nome', event.target.value)
                                }
                                value={form.data.nome}
                                required
                            />
                        </label>
                        {form.errors.nome && (
                            <p className="text-sm text-red-700">
                                {form.errors.nome}
                            </p>
                        )}
                        <label className="block text-sm font-medium text-slate-700">
                            Token da SellFlux
                            <input
                                autoComplete="new-password"
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
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
                                        : 'Opcional'
                                }
                                type="password"
                                value={form.data.sellflux_token}
                            />
                        </label>
                        {form.errors.sellflux_token && (
                            <p className="text-sm text-red-700">
                                {form.errors.sellflux_token}
                            </p>
                        )}
                        {editing?.sellflux_configurado && (
                            <label className="flex items-center gap-2 text-sm text-slate-700">
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
                                Remover o token desta unidade
                            </label>
                        )}
                        <div className="flex gap-2">
                            <button
                                className="rounded bg-blue-800 px-4 py-2 text-white"
                                disabled={form.processing}
                                type="submit"
                            >
                                {editing ? 'Salvar' : 'Cadastrar'}
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
                </section>
            </main>
        </>
    );
}
