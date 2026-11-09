import type { ReactNode } from 'react';
import { useEffect } from 'react';

type Props = {
    title: string;
    children: ReactNode;
    confirmLabel: string;
    tone?: 'primary' | 'danger';
    processing?: boolean;
    onConfirm: () => void;
    onClose: () => void;
};

/**
 * Confirmation in the same visual as the modals (D-034), replacing
 * window.confirm for actions that are hard to undo.
 */
export default function ConfirmDialog({
    title,
    children,
    confirmLabel,
    tone = 'primary',
    processing = false,
    onConfirm,
    onClose,
}: Props) {
    useEffect(() => {
        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                onClose();
            }
        }

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="es-modal" role="presentation">
            <button
                aria-label="Fechar"
                className="es-modal-backdrop"
                onClick={onClose}
                tabIndex={-1}
                type="button"
            />
            <section
                aria-labelledby="confirm-title"
                aria-modal="true"
                className="es-modal-card adm-confirm"
                role="alertdialog"
            >
                <div className="es-modal-head">
                    <h2 id="confirm-title">{title}</h2>
                    <button
                        aria-label="Fechar"
                        className="es-close"
                        onClick={onClose}
                        type="button"
                    >
                        ×
                    </button>
                </div>
                <div className="adm-confirm-body">{children}</div>
                <div className="es-actions">
                    <button
                        className="btn btn-secondary"
                        onClick={onClose}
                        type="button"
                    >
                        Cancelar
                    </button>
                    <button
                        autoFocus
                        className={`btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}`}
                        disabled={processing}
                        onClick={onConfirm}
                        type="button"
                    >
                        {processing ? 'Aguarde...' : confirmLabel}
                    </button>
                </div>
            </section>
        </div>
    );
}
