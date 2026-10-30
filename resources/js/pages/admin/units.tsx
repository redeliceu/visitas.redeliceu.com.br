import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Unit = { id: number; nome: string; ativo: boolean };
type Props = { units: Unit[] };

export default function Units({ units }: Props) {
    const [editing, setEditing] = useState<Unit | null>(null);
    const form = useForm({ nome: '' });

    function edit(unit: Unit) {
        setEditing(unit);
        form.setData('nome', unit.nome);
        form.clearErrors();
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
                                            <div className="flex gap-3">
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
