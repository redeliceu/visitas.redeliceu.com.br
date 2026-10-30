import type { FormEvent, ReactNode } from 'react';

type Props = {
    title: string;
    onClose: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    processing: boolean;
    submitLabel?: string;
    hideActions?: boolean;
    children: ReactNode;
};

export function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <label>
            {label}
            {children}
            {error && <span className="field-error">{error}</span>}
        </label>
    );
}

export default function RecordModal({
    title,
    onClose,
    onSubmit,
    processing,
    submitLabel = 'Salvar',
    hideActions = false,
    children,
}: Props) {
    return (
        <div className="es-modal" role="presentation">
            <section
                aria-labelledby="modal-title"
                aria-modal="true"
                className="es-modal-card"
                role="dialog"
            >
                <div className="es-modal-head">
                    <h2 id="modal-title">{title}</h2>
                    <button
                        aria-label="Fechar"
                        className="es-close"
                        onClick={onClose}
                        type="button"
                    >
                        ×
                    </button>
                </div>
                <form onSubmit={onSubmit}>
                    <div className="es-form">{children}</div>
                    {!hideActions && (
                        <div className="es-actions">
                            <button
                                className="btn btn-secondary"
                                onClick={onClose}
                                type="button"
                            >
                                Cancelar
                            </button>
                            <button
                                className="btn btn-primary"
                                disabled={processing}
                                type="submit"
                            >
                                {processing ? 'Salvando...' : submitLabel}
                            </button>
                        </div>
                    )}
                </form>
            </section>
        </div>
    );
}
