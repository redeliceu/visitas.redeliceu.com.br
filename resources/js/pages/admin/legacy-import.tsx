import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import VisitLayout from '@/components/visitas/VisitLayout';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type ImportReport = { filename: string; markdown: string };
type Props = { auth: Auth; units: Unit[] };

export default function LegacyImport({ auth, units }: Props) {
    const page = usePage<{
        flash?: { importReport?: ImportReport | null };
    }>();
    const form = useForm({
        unidade_id: '',
        arquivo: null as File | null,
    });
    const importReport = page.props.flash?.importReport;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/admin/legacy-import', { forceFormData: true });
    }

    function downloadReport() {
        if (!importReport) {
            return;
        }

        const blob = new Blob([importReport.markdown], {
            type: 'text/markdown;charset=utf-8',
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = importReport.filename;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    return (
        <VisitLayout
            title="Importar planilha antiga"
            active="legacy-import"
            auth={auth}
            units={[]}
            selectedUnitId={null}
        >
            <section className="es-report-section">
                <h1 className="es-report-title">
                    Importação histórica de leads
                </h1>
                <div className="es-panel max-w-3xl p-6">
                    <p className="mb-5 text-sm text-slate-700">
                        Selecione primeiro a unidade que receberá os dados. São
                        aceitas planilhas Excel no padrão de atendimentos do
                        sistema antigo (.xlsx ou .xls).
                    </p>
                    <form className="space-y-5" onSubmit={submit}>
                        <label className="block text-sm font-medium text-slate-800">
                            Unidade de destino
                            <select
                                className="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2"
                                value={form.data.unidade_id}
                                onChange={(event) =>
                                    form.setData(
                                        'unidade_id',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">Selecione uma unidade</option>
                                {units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.nome}
                                    </option>
                                ))}
                            </select>
                            {form.errors.unidade_id && (
                                <span className="mt-1 block text-sm text-red-700">
                                    {form.errors.unidade_id}
                                </span>
                            )}
                        </label>

                        <label className="block text-sm font-medium text-slate-800">
                            Planilha (.xlsx ou .xls)
                            <input
                                className="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm"
                                type="file"
                                accept=".xlsx,.xls"
                                onChange={(event) =>
                                    form.setData(
                                        'arquivo',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                                required
                            />
                            {form.errors.arquivo && (
                                <span className="mt-1 block text-sm text-red-700">
                                    {form.errors.arquivo}
                                </span>
                            )}
                        </label>

                        <div className="rounded border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
                            <p>
                                A coluna Data define a data da lead e seu
                                created_at. Leads existentes são atualizadas sem
                                alterar seu created_at; células vazias preservam
                                os dados atuais.
                            </p>
                            <p className="mt-2">
                                Vendedores são vinculados pelo nome na unidade
                                selecionada ou criados quando não existem.
                                Agendamentos sem horário recebem 12:00. Datas de
                                visita geram visitas vinculadas quando
                                aplicável; a coluna Data Matrícula é salva no
                                histórico da lead, sem criar matrícula em turma
                                física.
                            </p>
                            <p className="mt-2">
                                Linhas sem Data ou sem telefone/e-mail são
                                ignoradas. Valores da coluna Turma sem
                                correspondência no catálogo ficam sem série e
                                são informados no resultado.
                            </p>
                        </div>

                        <button
                            className="rounded bg-blue-900 px-4 py-2 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={
                                form.processing ||
                                form.data.unidade_id === '' ||
                                form.data.arquivo === null
                            }
                            type="submit"
                        >
                            {form.processing
                                ? 'Importando...'
                                : 'Importar planilha'}
                        </button>
                        {importReport && (
                            <div className="rounded border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                                <p>
                                    O relatório desta importação está pronto
                                    para download. Ele ficará disponível apenas
                                    enquanto esta página permanecer aberta.
                                </p>
                                <button
                                    className="mt-3 rounded bg-emerald-800 px-4 py-2 font-semibold text-white"
                                    onClick={downloadReport}
                                    type="button"
                                >
                                    Baixar relatório (.md)
                                </button>
                            </div>
                        )}
                    </form>
                </div>
            </section>
        </VisitLayout>
    );
}
