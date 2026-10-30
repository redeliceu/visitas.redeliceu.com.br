import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    user: { name: string; email: string };
};

export default function Profile({ user }: Props) {
    const form = useForm({
        name: user.name,
        email: user.email,
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put('/profile', {
            onSuccess: () => {
                form.setData('current_password', '');
                form.setData('password', '');
                form.setData('password_confirmation', '');
            },
        });
    }

    return (
        <>
            <Head title="Meu perfil" />
            <main className="min-h-screen bg-slate-100">
                <header className="bg-blue-900 px-6 py-4 text-white">
                    <h1 className="text-xl font-semibold">Meu perfil</h1>
                    <Link className="text-sm text-blue-100 underline" href="/">
                        Voltar ao início
                    </Link>
                </header>
                <form
                    className="mx-auto mt-8 max-w-xl space-y-5 rounded-lg bg-white p-6 shadow-sm"
                    onSubmit={submit}
                >
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
                    <fieldset className="space-y-4 border-t border-slate-200 pt-4">
                        <legend className="text-sm font-semibold text-slate-900">
                            Alterar senha (opcional)
                        </legend>
                        <label className="block text-sm font-medium text-slate-700">
                            Senha atual
                            <input
                                autoComplete="current-password"
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                onChange={(event) =>
                                    form.setData(
                                        'current_password',
                                        event.target.value,
                                    )
                                }
                                type="password"
                                value={form.data.current_password}
                            />
                            {form.errors.current_password && (
                                <span className="mt-1 block text-sm text-red-700">
                                    {form.errors.current_password}
                                </span>
                            )}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Nova senha (mínimo 12 caracteres)
                            <input
                                autoComplete="new-password"
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                type="password"
                                value={form.data.password}
                            />
                            {form.errors.password && (
                                <span className="mt-1 block text-sm text-red-700">
                                    {form.errors.password}
                                </span>
                            )}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Confirmar nova senha
                            <input
                                autoComplete="new-password"
                                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                onChange={(event) =>
                                    form.setData(
                                        'password_confirmation',
                                        event.target.value,
                                    )
                                }
                                type="password"
                                value={form.data.password_confirmation}
                            />
                        </label>
                    </fieldset>
                    <button
                        className="rounded bg-blue-800 px-4 py-2 text-white disabled:opacity-60"
                        disabled={form.processing}
                        type="submit"
                    >
                        Salvar perfil
                    </button>
                </form>
            </main>
        </>
    );
}
