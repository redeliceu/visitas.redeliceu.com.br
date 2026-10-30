type Props = { status: string | null | undefined };

export default function StatusPill({ status }: Props) {
    const normalized = status ?? 'Novo';
    const color =
        normalized === 'Matriculado'
            ? 'green'
            : normalized === 'Em Atendimento'
              ? 'blue'
              : normalized === 'Agendado'
                ? 'purple'
                : normalized === 'Compareceu'
                  ? 'blue'
                  : normalized === 'Não compareceu'
                    ? 'orange'
                    : normalized === 'Follow-up'
                      ? 'yellow'
                      : [
                              'Perdido',
                              'Sem Interesse',
                              'Cancelado',
                              'Faltou',
                          ].includes(normalized)
                        ? 'red'
                        : normalized === 'Aguardando'
                          ? 'slate'
                          : '';

    return <span className={`es-pill ${color}`.trim()}>{normalized}</span>;
}
