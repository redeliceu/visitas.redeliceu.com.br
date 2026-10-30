export type User = {
    id: number;
    name: string;
    email: string;
    role: 'master' | 'gestor' | 'atendimento';
    unidades: Array<{ id: number; nome: string }>;
    avatar?: string;
    [key: string]: unknown; // This allows for additional properties...
};

export type Auth = {
    user: User | null;
};
