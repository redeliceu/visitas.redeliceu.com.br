import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import type { Auth } from '@/types/auth';

type Unit = { id: number; nome: string };
type Props = {
    title: string;
    active:
        | 'leads'
        | 'agendamentos'
        | 'visitas'
        | 'home'
        | 'turmas'
        | 'campanhas'
        | 'relatorios'
        | 'vendedores'
        | 'painel-vendas';
    auth: Auth;
    units: Unit[];
    selectedUnitId: number | null;
    preservedQuery?: Record<string, string>;
    children: ReactNode;
};

const roleLabels = {
    master: 'Acesso master',
    gestor: 'Gestor de unidade',
    atendimento: 'Atendimento / Vendedor',
};

const navigation = [
    { href: '/', icon: 'fa-chart-line', label: 'Dashboard', key: 'home' },
    { href: '/leads', icon: 'fa-address-card', label: 'Leads', key: 'leads' },
    {
        href: '/agendamentos',
        icon: 'fa-calendar-check',
        label: 'Agendamentos',
        key: 'agendamentos',
    },
    { href: '/visitas', icon: 'fa-school', label: 'Visitas', key: 'visitas' },
    {
        href: '/vendedores',
        icon: 'fa-users',
        label: 'Vendedores',
        key: 'vendedores',
    },
    {
        href: '/painel-vendas',
        icon: 'fa-chart-pie',
        label: 'Painel de Vendas',
        key: 'painel-vendas',
    },
    {
        href: '/turmas',
        icon: 'fa-table-cells-large',
        label: 'Mapa de Turmas',
        key: 'turmas',
    },
    {
        href: '/campanhas',
        icon: 'fa-bullseye',
        label: 'Metas',
        key: 'campanhas',
    },
    {
        href: '/relatorios',
        icon: 'fa-file-csv',
        label: 'Relatórios',
        key: 'relatorios',
    },
];

export default function VisitLayout({
    title,
    active,
    auth,
    units,
    selectedUnitId,
    preservedQuery = {},
    children,
}: Props) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const page = usePage<{
        errors?: Record<string, string>;
        flash?: { success?: string | null };
    }>();
    const user = auth.user;
    const firstError = Object.values(page.props.errors ?? {})[0];

    if (!user) {
        return null;
    }

    function selectUnit(unitId: string) {
        router.get(
            window.location.pathname,
            { ...preservedQuery, ...(unitId ? { unidade_id: unitId } : {}) },
            { preserveState: false, replace: true },
        );
    }

    return (
        <>
            <Head title={title} />
            <div className="app-container">
                <aside
                    className={`sidebar${sidebarOpen ? ' open' : ''}`}
                    id="sidebar"
                >
                    <div className="sidebar-header">
                        <img
                            className="sidebar-logo"
                            src="/images/rede-liceu.png"
                            alt="Rede Liceu"
                        />
                        <p className="sidebar-subtitle">
                            Sistema de Controle de Visitas
                        </p>
                    </div>
                    <ul className="nav-menu">
                        {navigation.map((item) => (
                            <li key={item.key}>
                                <a
                                    className={`nav-item${active === item.key ? ' active' : ''}`}
                                    href={item.href}
                                    aria-current={
                                        active === item.key ? 'page' : undefined
                                    }
                                    onClick={() => setSidebarOpen(false)}
                                >
                                    <i
                                        className={`fas ${item.icon}`}
                                        aria-hidden="true"
                                    />
                                    <span>{item.label}</span>
                                </a>
                            </li>
                        ))}
                        {user.role === 'master' && (
                            <>
                                <li>
                                    <a className="nav-item" href="/admin/users">
                                        <i
                                            className="fas fa-users"
                                            aria-hidden="true"
                                        />
                                        <span>Usuários</span>
                                    </a>
                                </li>
                                <li>
                                    <a className="nav-item" href="/admin/units">
                                        <i
                                            className="fas fa-school"
                                            aria-hidden="true"
                                        />
                                        <span>Unidades</span>
                                    </a>
                                </li>
                            </>
                        )}
                    </ul>
                </aside>
                <button
                    aria-label="Fechar menu"
                    className={`sidebar-mobile-overlay${sidebarOpen ? ' show' : ''}`}
                    onClick={() => setSidebarOpen(false)}
                    type="button"
                />
                <main className="main-content">
                    <header className="topbar">
                        <button
                            aria-label="Abrir menu"
                            className="mobile-menu-btn"
                            onClick={() => setSidebarOpen(true)}
                            type="button"
                        >
                            <i className="fas fa-bars" aria-hidden="true" />
                        </button>
                        <div className="topbar-left">
                            <div className="topbar-title">{title}</div>
                            <div className="topbar-breadcrumb">
                                Início / {title}
                            </div>
                        </div>
                        <div className="topbar-right">
                            {units.length > 0 && (
                                <select
                                    id="unidade"
                                    aria-label="Selecionar unidade"
                                    onChange={(event) =>
                                        selectUnit(event.target.value)
                                    }
                                    title="Selecionar unidade"
                                    value={selectedUnitId ?? ''}
                                >
                                    {user.role === 'master' && (
                                        <option value="">
                                            Todas as unidades
                                        </option>
                                    )}
                                    {user.role !== 'master' &&
                                        units.length > 1 && (
                                            <option value="">
                                                Todas as unidades vinculadas
                                            </option>
                                        )}
                                    {units.map((unit) => (
                                        <option key={unit.id} value={unit.id}>
                                            {unit.nome}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <div className="topbar-user">
                                <button
                                    aria-expanded={userMenuOpen}
                                    className="topbar-user-btn"
                                    onClick={() =>
                                        setUserMenuOpen((isOpen) => !isOpen)
                                    }
                                    type="button"
                                >
                                    <span className="topbar-avatar">
                                        {user.name.charAt(0).toUpperCase()}
                                    </span>
                                    <span className="topbar-user-copy">
                                        <span className="topbar-user-name">
                                            {user.name}
                                        </span>
                                        <span className="topbar-user-role">
                                            {roleLabels[user.role]}
                                        </span>
                                    </span>
                                    <i
                                        className="fas fa-chevron-down"
                                        aria-hidden="true"
                                    />
                                </button>
                                <div
                                    className={`topbar-user-menu${userMenuOpen ? ' open' : ''}`}
                                >
                                    <button
                                        onClick={() => router.get('/profile')}
                                        type="button"
                                    >
                                        <i
                                            className="fas fa-user"
                                            aria-hidden="true"
                                        />
                                        Meu perfil
                                    </button>
                                    <button
                                        className="logout-item"
                                        onClick={() => router.post('/logout')}
                                        type="button"
                                    >
                                        <i
                                            className="fas fa-right-from-bracket"
                                            aria-hidden="true"
                                        />
                                        Sair
                                    </button>
                                </div>
                            </div>
                        </div>
                    </header>
                    <div className="content">
                        {page.props.flash?.success && (
                            <div className="visit-notice" role="status">
                                {page.props.flash.success}
                            </div>
                        )}
                        {firstError && (
                            <div className="visit-notice error" role="alert">
                                {firstError}
                            </div>
                        )}
                        {children}
                    </div>
                </main>
            </div>
        </>
    );
}
