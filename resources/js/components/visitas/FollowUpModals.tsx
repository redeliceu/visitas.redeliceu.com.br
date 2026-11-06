import { router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

export type FollowUpNote = {
    id: number;
    texto: string;
    proximo_retorno: string | null;
    created_at: string;
    edited: boolean;
    autor: string | null;
    vendedor: string | null;
    can_manage: boolean;
};

export type FollowUpLead = {
    id: number;
    responsavel: string | null;
    telefone: string | null;
    aluno: string | null;
    serie: string | null;
    status: string | null;
    retorno_data: string | null;
    overdue: boolean;
    bucket: string | null;
    follow_ups_count: number;
    initial_note: { texto: string; data: string | null } | null;
    follow_ups: FollowUpNote[];
};

const MAX_LENGTH = 2000;

function shortDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-');

    return `${day}/${month}/${year}`;
}

function dayMonth(value: string): string {
    const [, month, day] = value.slice(0, 10).split('-');

    return `${day}/${month}`;
}

function localDay(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** "Hoje", "Ontem" or "Terça, 04/11" for the timeline groups. */
function dayLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (localDay(date) === localDay(today)) {
        return 'Hoje';
    }

    if (localDay(date) === localDay(yesterday)) {
        return 'Ontem';
    }

    const weekday = date.toLocaleDateString('pt-BR', { weekday: 'long' });
    const label = weekday.charAt(0).toUpperCase() + weekday.slice(1);

    return `${label.replace('-feira', '')}, ${dayMonth(localDay(date))}`;
}

function timeLabel(iso: string): string {
    return new Date(iso).toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function initials(name: string | null): string {
    return (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function returnChip(
    lead: FollowUpLead,
): { label: string; tone: 'danger' | 'info' | 'neutral' } | null {
    if (!lead.retorno_data) {
        return null;
    }

    if (lead.overdue) {
        return {
            label: `Retorno vencido · ${dayMonth(lead.retorno_data)}`,
            tone: 'danger',
        };
    }

    if (lead.bucket === 'hoje') {
        return { label: 'Retorno hoje', tone: 'info' };
    }

    return { label: `Retorno ${dayMonth(lead.retorno_data)}`, tone: 'neutral' };
}

function ModalShell({
    titleId,
    onClose,
    children,
}: {
    titleId: string;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <div className="es-modal" role="presentation">
            <button
                aria-label="Fechar modal"
                className="es-modal-backdrop"
                onClick={onClose}
                tabIndex={-1}
                type="button"
            />
            <section
                aria-labelledby={titleId}
                aria-modal="true"
                className="fu-modal"
                role="dialog"
            >
                {children}
            </section>
        </div>
    );
}

async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const copied = document.execCommand('copy');
        document.body.removeChild(area);

        return copied;
    }
}

/** Button labelled with the phone itself that copies it (D-033). */
function CopyPhoneButton({ phone }: { phone: string }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        if (await copyText(phone)) {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
    }

    return (
        <button
            aria-label={`Copiar telefone ${phone}`}
            className={`fu-copy-phone${copied ? ' is-copied' : ''}`}
            onClick={() => void copy()}
            title="Copiar telefone"
            type="button"
        >
            <svg
                aria-hidden="true"
                fill="none"
                height="14"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                viewBox="0 0 24 24"
                width="14"
            >
                {copied ? (
                    <path d="M20 6 9 17l-5-5" />
                ) : (
                    <>
                        <rect height="13" rx="2" width="13" x="9" y="9" />
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                    </>
                )}
            </svg>
            <span>{phone}</span>
            <span aria-live="polite" className="fu-copy-status">
                {copied ? 'Copiado' : ''}
            </span>
        </button>
    );
}

function LeadHeader({
    lead,
    eyebrow,
    titleId,
    onClose,
    onSchedule,
    showCopyPhone = false,
}: {
    lead: FollowUpLead;
    eyebrow: string;
    titleId: string;
    onClose: () => void;
    /** Opens the scheduling modal; omitted in the scheduling modal itself. */
    onSchedule?: () => void;
    /** Copy-phone button next to the name, in the follow-ups modal (D-033). */
    showCopyPhone?: boolean;
}) {
    const chip = returnChip(lead);
    const student = [lead.aluno, lead.serie].filter(Boolean).join(' · ');

    return (
        <header className="fu-header">
            <span aria-hidden="true" className="fu-avatar">
                {initials(lead.responsavel)}
            </span>
            <div className="fu-header-body">
                <div className="fu-eyebrow">{eyebrow}</div>
                <div className="fu-title-row">
                    <h2 id={titleId}>{lead.responsavel ?? 'Lead'}</h2>
                    {showCopyPhone && lead.telefone && (
                        <CopyPhoneButton phone={lead.telefone} />
                    )}
                </div>
                <div className="fu-chips">
                    {student && <span className="fu-chip">{student}</span>}
                    {lead.status && (
                        <span className="fu-chip fu-chip-info">
                            {lead.status}
                        </span>
                    )}
                    {chip && (
                        <span className={`fu-chip fu-chip-${chip.tone}`}>
                            {chip.label}
                        </span>
                    )}
                </div>
            </div>
            <div className="fu-header-actions">
                {onSchedule && (
                    <button
                        className="fu-btn fu-btn-secondary"
                        onClick={onSchedule}
                        type="button"
                    >
                        Agendar visita
                    </button>
                )}
                <button
                    aria-label="Fechar"
                    className="fu-close"
                    onClick={onClose}
                    type="button"
                >
                    ×
                </button>
            </div>
        </header>
    );
}

/** Always-open composer at the top of the modal (D-031). */
function Composer({
    leadId,
    autoFocus = false,
    onSaved,
}: {
    leadId: number;
    autoFocus?: boolean;
    onSaved?: () => void;
}) {
    const form = useForm({ texto: '', proximo_retorno: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/leads/${leadId}/follow-ups`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSaved?.();
            },
        });
    }

    return (
        <form className="fu-composer" onSubmit={submit}>
            <label htmlFor={`fu-texto-${leadId}`}>Novo follow-up</label>
            <textarea
                autoFocus={autoFocus}
                id={`fu-texto-${leadId}`}
                maxLength={MAX_LENGTH}
                onChange={(event) => form.setData('texto', event.target.value)}
                placeholder="Como foi o contato? O que ficou combinado?"
                required
                rows={3}
                value={form.data.texto}
            />
            {form.errors.texto && (
                <span className="field-error">{form.errors.texto}</span>
            )}
            <div className="fu-composer-row">
                <label htmlFor={`fu-retorno-${leadId}`}>
                    Próximo retorno
                    <input
                        id={`fu-retorno-${leadId}`}
                        onChange={(event) =>
                            form.setData('proximo_retorno', event.target.value)
                        }
                        type="date"
                        value={form.data.proximo_retorno}
                    />
                </label>
                <span className="fu-count">
                    {form.data.texto.length}/{MAX_LENGTH}
                </span>
                <button
                    className="fu-btn fu-btn-primary"
                    disabled={form.processing || form.data.texto.trim() === ''}
                    type="submit"
                >
                    {form.processing ? 'Salvando...' : 'Registrar'}
                </button>
            </div>
            {form.errors.proximo_retorno && (
                <span className="field-error">
                    {form.errors.proximo_retorno}
                </span>
            )}
            <p className="fu-hint">
                Com próximo retorno preenchido, a data de retorno do lead também
                é atualizada.
            </p>
        </form>
    );
}

function NoteEditor({
    note,
    onDone,
}: {
    note: FollowUpNote;
    onDone: () => void;
}) {
    const form = useForm({
        texto: note.texto,
        proximo_retorno: note.proximo_retorno ?? '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put(`/follow-ups/${note.id}`, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    }

    return (
        <form className="fu-editor" onSubmit={submit}>
            <label htmlFor={`fu-edit-${note.id}`}>Anotação</label>
            <textarea
                id={`fu-edit-${note.id}`}
                maxLength={MAX_LENGTH}
                onChange={(event) => form.setData('texto', event.target.value)}
                required
                rows={4}
                value={form.data.texto}
            />
            {form.errors.texto && (
                <span className="field-error">{form.errors.texto}</span>
            )}
            <div className="fu-composer-row">
                <label htmlFor={`fu-edit-ret-${note.id}`}>
                    Próximo retorno
                    <input
                        id={`fu-edit-ret-${note.id}`}
                        onChange={(event) =>
                            form.setData('proximo_retorno', event.target.value)
                        }
                        type="date"
                        value={form.data.proximo_retorno}
                    />
                </label>
                <span className="fu-count">
                    {form.data.texto.length}/{MAX_LENGTH}
                </span>
                <button
                    className="fu-btn fu-btn-secondary"
                    onClick={onDone}
                    type="button"
                >
                    Cancelar
                </button>
                <button
                    className="fu-btn fu-btn-primary"
                    disabled={form.processing || form.data.texto.trim() === ''}
                    type="submit"
                >
                    {form.processing ? 'Salvando...' : 'Salvar'}
                </button>
            </div>
        </form>
    );
}

const editIcon = (
    <svg
        aria-hidden="true"
        fill="none"
        height="15"
        stroke="currentColor"
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth="2"
        viewBox="0 0 24 24"
        width="15"
    >
        <path d="M12 20h9" />
        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
    </svg>
);

const deleteIcon = (
    <svg
        aria-hidden="true"
        fill="none"
        height="15"
        stroke="currentColor"
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth="2"
        viewBox="0 0 24 24"
        width="15"
    >
        <path d="M3 6h18" />
        <path d="M8 6V4h8v2" />
        <path d="M19 6l-1 14H6L5 6" />
    </svg>
);

/**
 * Visit scheduling from the seller panel, same look as the follow-up modals
 * (D-032). Uses the existing lead scheduling endpoint and stays on the page.
 */
export function ScheduleVisitModal({
    lead,
    onClose,
}: {
    lead: FollowUpLead;
    onClose: () => void;
}) {
    const titleId = `fu-schedule-title-${lead.id}`;
    const form = useForm({ data: '', horario: '', permanecer: true });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/leads/${lead.id}/schedule`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <ModalShell onClose={onClose} titleId={titleId}>
            <LeadHeader
                eyebrow="Agendar visita"
                lead={lead}
                onClose={onClose}
                titleId={titleId}
            />
            <form className="fu-composer fu-schedule" onSubmit={submit}>
                <div className="fu-schedule-fields">
                    <label htmlFor={`fu-sched-data-${lead.id}`}>
                        Data
                        <input
                            autoFocus
                            id={`fu-sched-data-${lead.id}`}
                            onChange={(event) =>
                                form.setData('data', event.target.value)
                            }
                            required
                            type="date"
                            value={form.data.data}
                        />
                        {form.errors.data && (
                            <span className="field-error">
                                {form.errors.data}
                            </span>
                        )}
                    </label>
                    <label htmlFor={`fu-sched-hora-${lead.id}`}>
                        Horário
                        <input
                            id={`fu-sched-hora-${lead.id}`}
                            onChange={(event) =>
                                form.setData('horario', event.target.value)
                            }
                            required
                            type="time"
                            value={form.data.horario}
                        />
                        {form.errors.horario && (
                            <span className="field-error">
                                {form.errors.horario}
                            </span>
                        )}
                    </label>
                </div>
                <p className="fu-hint">
                    O agendamento fica atribuído ao vendedor do lead, e o lead
                    passa para a situação Agendado.
                </p>
                <div className="fu-composer-row">
                    <button
                        className="fu-btn fu-btn-secondary"
                        onClick={onClose}
                        type="button"
                    >
                        Cancelar
                    </button>
                    <button
                        className="fu-btn fu-btn-primary"
                        disabled={
                            form.processing ||
                            form.data.data === '' ||
                            form.data.horario === ''
                        }
                        type="submit"
                    >
                        {form.processing ? 'Agendando...' : 'Agendar'}
                    </button>
                </div>
            </form>
        </ModalShell>
    );
}

/** Modal opened by "Registrar contato": header and composer only (D-029/D-031). */
export function CreateFollowUpModal({
    lead,
    onClose,
    onSchedule,
}: {
    lead: FollowUpLead;
    onClose: () => void;
    onSchedule?: () => void;
}) {
    const titleId = `fu-create-title-${lead.id}`;

    return (
        <ModalShell onClose={onClose} titleId={titleId}>
            <LeadHeader
                eyebrow="Registrar contato"
                lead={lead}
                onClose={onClose}
                onSchedule={onSchedule}
                titleId={titleId}
            />
            <Composer autoFocus leadId={lead.id} onSaved={onClose} />
        </ModalShell>
    );
}

/** Follow-ups timeline of a lead, newest first (D-029/D-031). */
export function FollowUpsModal({
    lead,
    onClose,
    onSchedule,
}: {
    lead: FollowUpLead;
    onClose: () => void;
    onSchedule?: () => void;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const titleId = `fu-title-${lead.id}`;
    const latestId = lead.follow_ups[0]?.id;
    const groups: Array<{ label: string; notes: FollowUpNote[] }> = [];

    for (const note of lead.follow_ups) {
        const label = dayLabel(note.created_at);
        const last = groups[groups.length - 1];

        if (last && last.label === label) {
            last.notes.push(note);
        } else {
            groups.push({ label, notes: [note] });
        }
    }

    function remove(note: FollowUpNote) {
        if (!window.confirm('Excluir este follow-up?')) {
            return;
        }

        router.delete(`/follow-ups/${note.id}`, { preserveScroll: true });
    }

    return (
        <ModalShell onClose={onClose} titleId={titleId}>
            <LeadHeader
                eyebrow={`Follow-ups · ${lead.follow_ups_count}`}
                lead={lead}
                onClose={onClose}
                onSchedule={onSchedule}
                showCopyPhone
                titleId={titleId}
            />
            <Composer leadId={lead.id} />
            <div className="fu-timeline-wrap">
                {lead.follow_ups_count === 0 && (
                    <p className="fu-empty">
                        Nenhum follow-up registrado ainda.
                    </p>
                )}
                {groups.map((group) => (
                    <section className="fu-day" key={group.label}>
                        <h3>{group.label}</h3>
                        <ol className="fu-timeline">
                            {group.notes.map((note) => {
                                const isLatest = note.id === latestId;

                                return (
                                    <li
                                        className={`fu-item${isLatest ? ' is-latest' : ''}`}
                                        key={note.id}
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="fu-dot"
                                        />
                                        <div className="fu-card">
                                            <div className="fu-card-meta">
                                                <strong>
                                                    {timeLabel(note.created_at)}
                                                </strong>
                                                <span>
                                                    {note.autor ??
                                                        'Usuário removido'}
                                                    {note.vendedor &&
                                                    note.vendedor !== note.autor
                                                        ? ` · vendedor ${note.vendedor}`
                                                        : ''}
                                                    {isLatest
                                                        ? ' · mais recente'
                                                        : ''}
                                                    {note.edited
                                                        ? ' · editado'
                                                        : ''}
                                                </span>
                                                {note.can_manage &&
                                                    editingId !== note.id && (
                                                        <span className="fu-card-actions">
                                                            <button
                                                                aria-label="Editar follow-up"
                                                                className="fu-icon-btn"
                                                                onClick={() =>
                                                                    setEditingId(
                                                                        note.id,
                                                                    )
                                                                }
                                                                type="button"
                                                            >
                                                                {editIcon}
                                                            </button>
                                                            <button
                                                                aria-label="Excluir follow-up"
                                                                className="fu-icon-btn fu-icon-danger"
                                                                onClick={() =>
                                                                    remove(note)
                                                                }
                                                                type="button"
                                                            >
                                                                {deleteIcon}
                                                            </button>
                                                        </span>
                                                    )}
                                            </div>
                                            {editingId === note.id ? (
                                                <NoteEditor
                                                    note={note}
                                                    onDone={() =>
                                                        setEditingId(null)
                                                    }
                                                />
                                            ) : (
                                                <>
                                                    <p className="fu-text">
                                                        {note.texto}
                                                    </p>
                                                    {note.proximo_retorno && (
                                                        <span className="fu-return">
                                                            Próximo retorno:{' '}
                                                            {dayMonth(
                                                                note.proximo_retorno,
                                                            )}
                                                        </span>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                ))}
                {lead.initial_note && (
                    <section className="fu-day">
                        <h3>
                            {lead.initial_note.data
                                ? `${shortDate(lead.initial_note.data)} · início`
                                : 'Início'}
                        </h3>
                        <ol className="fu-timeline">
                            <li className="fu-item is-initial">
                                <span aria-hidden="true" className="fu-dot" />
                                <div className="fu-card">
                                    <div className="fu-card-meta">
                                        <strong>Observação inicial</strong>
                                        <span>Editada na tela de Leads</span>
                                    </div>
                                    <p className="fu-text">
                                        {lead.initial_note.texto}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </section>
                )}
            </div>
        </ModalShell>
    );
}
