import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

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
type Attendant = {
    id: number;
    nome: string;
    situacao: 'pendente' | 'associado' | 'ignorado';
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
type SharedProps = {
    flash?: { success?: string | null; warning?: string | null };
    errors?: Record<string, string>;
};

const NEW_SELLER = 'criar';

function initialChoice(attendant: Attendant): string {
    if (attendant.vendedor_id) {
        return String(attendant.vendedor_id);
    }

    if (attendant.sugestao?.tipo === 'vendedor') {
        return String(attendant.sugestao.vendedor_id);
    }

    return attendant.sugestao?.tipo === 'criar' ? NEW_SELLER : '';
}

function suggestionText(
    attendant: Attendant,
    sellers: Seller[],
): string | null {
    const suggestion = attendant.sugestao;

    if (suggestion?.tipo === 'criar') {
        return 'Sugestão: criar um vendedor novo para esta pessoa.';
    }

    if (suggestion?.tipo === 'bloqueado') {
        return `Existe um cadastro inativo com este nome, mas não dá para reativá-lo aqui: ${suggestion.motivo}.`;
    }

    if (suggestion?.tipo === 'vendedor') {
        const seller = sellers.find(
            (item) => item.id === suggestion.vendedor_id,
        );

        return suggestion.reativar
            ? `Sugestão: reativar ${seller?.nome} e associar.`
            : `Sugestão pelo nome: ${seller?.nome}.`;
    }

    return null;
}

function AttendantRow({
    attendant,
    sellers,
    updateUrl,
    disabled,
}: {
    attendant: Attendant;
    sellers: Seller[];
    updateUrl: string;
    disabled: boolean;
}) {
    const [choice, setChoice] = useState(initialChoice(attendant));
    const [newName, setNewName] = useState(attendant.nome);
    const [saving, setSaving] = useState(false);
    const activeSellers = sellers.filter((seller) => seller.ativo);
    const inactiveSellers = sellers.filter((seller) => !seller.ativo);
    const chosenSeller = sellers.find((seller) => String(seller.id) === choice);
    const hint = suggestionText(attendant, sellers);

    function send(data: Record<string, string | number>) {
        router.put(updateUrl, data, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
        });
    }

    function save() {
        if (choice === NEW_SELLER) {
            send({ acao: 'criar', nome: newName });
        } else if (choice !== '') {
            send({ acao: 'associar', vendedor_id: Number(choice) });
        }
    }

    const blocked = disabled || saving;
    const unchanged =
        attendant.situacao === 'associado' &&
        choice === String(attendant.vendedor_id);

    return (
        <li className="space-y-3 border-b border-slate-200 py-4 last:border-b-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-medium text-slate-900">
                        {attendant.nome}
                    </p>
                    {attendant.visto_em && (
                        <p className="text-xs text-slate-500">
                            Visto em {attendant.visto_em}
                        </p>
                    )}
                </div>
                {attendant.situacao === 'pendente' && (
                    <span className="rounded bg-amber-100 px-2 py-1 text-xs text-amber-900">
                        Pendente
                    </span>
                )}
                {attendant.situacao === 'associado' && (
                    <span className="rounded bg-emerald-100 px-2 py-1 text-xs text-emerald-900">
                        Associado a {attendant.vendedor_nome}
                    </span>
                )}
                {attendant.situacao === 'ignorado' && (
                    <span className="rounded bg-slate-200 px-2 py-1 text-xs text-slate-700">
                        Ignorado
                    </span>
                )}
            </div>
            {attendant.situacao === 'associado' &&
                attendant.vendedor_ativo === false && (
                    <p className="text-sm text-red-700">
                        Este vendedor está inativo. Leads deste atendente não
                        serão vinculados até ele ser reativado ou trocado.
                    </p>
                )}
            {hint && <p className="text-sm text-slate-600">{hint}</p>}
            <div className="flex flex-wrap items-end gap-2">
                <label className="min-w-56 flex-1 text-sm font-medium text-slate-700">
                    Vendedor
                    <select
                        aria-label={`Vendedor de ${attendant.nome}`}
                        className="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 font-normal"
                        disabled={blocked}
                        onChange={(event) => setChoice(event.target.value)}
                        value={choice}
                    >
                        <option value="">Selecione</option>
                        {activeSellers.length > 0 && (
                            <optgroup label="Vendedores ativos">
                                {activeSellers.map((seller) => (
                                    <option key={seller.id} value={seller.id}>
                                        {seller.nome}
                                        {seller.tem_conta ? '' : ' (sem conta)'}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                        {inactiveSellers.length > 0 && (
                            <optgroup label="Inativos (reativa ao salvar)">
                                {inactiveSellers.map((seller) => (
                                    <option
                                        disabled={
                                            seller.bloqueio_reativacao !== null
                                        }
                                        key={seller.id}
                                        value={seller.id}
                                    >
                                        {seller.nome}
                                        {seller.bloqueio_reativacao
                                            ? ` (${seller.bloqueio_reativacao})`
                                            : ''}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                        <option value={NEW_SELLER}>Criar vendedor novo</option>
                    </select>
                </label>
                {choice === NEW_SELLER && (
                    <label className="min-w-56 flex-1 text-sm font-medium text-slate-700">
                        Nome do novo vendedor
                        <input
                            className="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal"
                            disabled={blocked}
                            maxLength={255}
                            onChange={(event) => setNewName(event.target.value)}
                            value={newName}
                        />
                    </label>
                )}
                <button
                    className="rounded bg-blue-800 px-4 py-2 text-white disabled:opacity-50"
                    disabled={
                        blocked ||
                        choice === '' ||
                        unchanged ||
                        (choice === NEW_SELLER && newName.trim() === '')
                    }
                    onClick={save}
                    type="button"
                >
                    {chosenSeller && !chosenSeller.ativo
                        ? 'Reativar e associar'
                        : choice === NEW_SELLER
                          ? 'Criar e associar'
                          : 'Associar'}
                </button>
                {attendant.situacao !== 'ignorado' && (
                    <button
                        className="rounded border border-slate-300 px-4 py-2 disabled:opacity-50"
                        disabled={blocked}
                        onClick={() => send({ acao: 'ignorar' })}
                        type="button"
                    >
                        Ignorar
                    </button>
                )}
                {attendant.situacao !== 'pendente' && (
                    <button
                        className="rounded border border-slate-300 px-4 py-2 disabled:opacity-50"
                        disabled={blocked}
                        onClick={() => send({ acao: 'desfazer' })}
                        type="button"
                    >
                        Desfazer
                    </button>
                )}
            </div>
        </li>
    );
}

export default function SellfluxAtendentes({
    unit,
    semVendedorAtivo,
    sellers,
    atendentes,
}: Props) {
    const { flash, errors } = usePage<SharedProps>().props;
    const [syncing, setSyncing] = useState(false);
    const errorMessages = Object.values(errors ?? {});

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

    return (
        <>
            <Head title={`Atendentes SellFlux: ${unit.nome}`} />
            <main className="min-h-screen bg-slate-100">
                <header className="flex items-center justify-between bg-blue-900 px-6 py-4 text-white">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Atendentes da SellFlux: {unit.nome}
                        </h1>
                        <Link
                            className="text-sm text-blue-100 underline"
                            href="/admin/units"
                        >
                            Voltar às unidades
                        </Link>
                    </div>
                </header>
                <section className="mx-auto max-w-5xl space-y-4 px-6 py-8">
                    {flash?.success && (
                        <p className="rounded bg-emerald-100 px-4 py-3 text-sm text-emerald-900">
                            {flash.success}
                        </p>
                    )}
                    {flash?.warning && (
                        <p className="rounded bg-amber-100 px-4 py-3 text-sm text-amber-900">
                            {flash.warning}
                        </p>
                    )}
                    {errorMessages.map((message) => (
                        <p
                            className="rounded bg-red-100 px-4 py-3 text-sm text-red-800"
                            key={message}
                        >
                            {message}
                        </p>
                    ))}
                    {semVendedorAtivo && (
                        <p className="rounded bg-amber-100 px-4 py-3 text-sm text-amber-900">
                            Esta unidade não tem vendedor ativo. Para cada
                            atendente, reative um vendedor inativo ou crie um
                            novo. Sem isso, os leads dela não têm a quem ser
                            vinculados.
                        </p>
                    )}
                    <div className="rounded-lg bg-white p-5 shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900">
                                    Quem atende nesta unidade
                                </h2>
                                <p className="text-sm text-slate-600">
                                    Leads só são vinculados a atendentes
                                    associados aqui. Quem não é vendedor pode
                                    ser ignorado.
                                </p>
                            </div>
                            <button
                                className="rounded bg-blue-800 px-4 py-2 text-white disabled:opacity-50"
                                disabled={
                                    syncing ||
                                    !unit.ativo ||
                                    !unit.sellflux_configurado
                                }
                                onClick={sync}
                                type="button"
                            >
                                {syncing ? 'Atualizando...' : 'Atualizar lista'}
                            </button>
                        </div>
                        {atendentes.length === 0 ? (
                            <p className="mt-4 text-sm text-slate-600">
                                Nenhum atendente na lista. Clique em Atualizar
                                lista para buscar quem está atendendo na
                                SellFlux.
                            </p>
                        ) : (
                            <ul className="mt-2">
                                {atendentes.map((attendant) => (
                                    <AttendantRow
                                        attendant={attendant}
                                        disabled={!unit.ativo}
                                        key={`${attendant.id}-${attendant.situacao}-${attendant.vendedor_id}`}
                                        sellers={sellers}
                                        updateUrl={`/admin/units/${unit.id}/atendentes/${attendant.id}`}
                                    />
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            </main>
        </>
    );
}
