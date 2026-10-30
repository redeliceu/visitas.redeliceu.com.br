import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login() {
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/login');
    }

    return (
        <>
            <Head title="Acesso" />
            <main className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-10">
                <section className="w-full max-w-md rounded-xl bg-white p-8 shadow-lg">
                    <div className="mb-8 text-center">
                        <h1 className="text-2xl font-semibold text-slate-900">
                            Liceu Brasil
                        </h1>
                        <p className="mt-2 text-sm text-slate-600">
                            Sistema de Controle de Visitas
                        </p>
                    </div>
                    <form className="space-y-5" onSubmit={submit}>
                        <div>
                            <label
                                className="mb-1 block text-sm font-medium text-slate-700"
                                htmlFor="email"
                            >
                                E-mail
                            </label>
                            <input
                                autoComplete="username"
                                className="w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-blue-700 focus:ring-2 focus:ring-blue-700/20 focus:outline-none"
                                id="email"
                                name="email"
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                type="email"
                                value={form.data.email}
                                required
                            />
                            {form.errors.email && (
                                <p className="mt-1 text-sm text-red-700">
                                    {form.errors.email}
                                </p>
                            )}
                        </div>
                        <div>
                            <label
                                className="mb-1 block text-sm font-medium text-slate-700"
                                htmlFor="password"
                            >
                                Senha
                            </label>
                            <input
                                autoComplete="current-password"
                                className="w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-blue-700 focus:ring-2 focus:ring-blue-700/20 focus:outline-none"
                                id="password"
                                name="password"
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                type="password"
                                value={form.data.password}
                                required
                            />
                            {form.errors.password && (
                                <p className="mt-1 text-sm text-red-700">
                                    {form.errors.password}
                                </p>
                            )}
                        </div>
                        <label className="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                checked={form.data.remember}
                                onChange={(event) =>
                                    form.setData(
                                        'remember',
                                        event.target.checked,
                                    )
                                }
                                type="checkbox"
                            />
                            Manter conectado
                        </label>
                        <button
                            className="w-full rounded-md bg-blue-800 px-4 py-2.5 font-medium text-white hover:bg-blue-900 disabled:opacity-60"
                            disabled={form.processing}
                            type="submit"
                        >
                            Entrar
                        </button>
                    </form>
                    <p className="mt-6 text-center text-xs text-slate-500">
                        O acesso é criado pela gestão do sistema.
                    </p>
                </section>
            </main>
        </>
    );
}
