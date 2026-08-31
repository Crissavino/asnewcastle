import { Head, Link, router, usePage } from '@inertiajs/react';
import { IconAgenda, IconTabla, IconVestuario, IconCuota, IconPerfil } from '../Components/TabIcons';
import AndroidUpdate from '../Components/AndroidUpdate';
import Crest from '../Components/Crest';
import Kit from '../Components/Kit';
import NotificationBell from '../Components/NotificationBell';
import { useTranslations } from '../i18n';

const TABS = [
    { key: 'agenda', route: 'agenda', Icon: IconAgenda },
    { key: 'tabla', route: 'tabla', Icon: IconTabla },
    { key: 'vestuario', route: 'vestuario', Icon: IconVestuario },
    { key: 'cuota', route: 'cuota', Icon: IconCuota },
    { key: 'perfil', route: 'perfil', Icon: IconPerfil },
];

function NavLinks({ iconSize }) {
    const { t } = useTranslations();
    const { url } = usePage();

    return TABS.map(({ key, route: name, Icon }) => (
        <Link key={key} href={route(name)} className={url.startsWith(`/${name}`) ? 'on' : ''}>
            <Icon size={iconSize} strokeWidth={2} />
            {t(`tabs.${key}`)}
        </Link>
    ));
}

export default function AppLayout({ tab, eyebrow, hideNav = false, children }) {
    const { t } = useTranslations();
    const { auth, club, member, is_owner, viewing_as_player } = usePage().props;

    const title = t(`tabs.${tab}`);

    const switchView = () => router.post(route('ver-como'), {}, { preserveScroll: true });

    return (
        <div className="nc-root">
            <Head title={title} />
            <AndroidUpdate />
            <div className="nc-shell">
                {/* Riel / sidebar: solo tablet y desktop */}
                <aside className="nc-side">
                    <div className="nc-side-brand">
                        <Crest size={40} white />
                        <div>
                            <div className="nc-side-name">{club?.name}</div>
                            <div className="nc-side-league">{club?.league}</div>
                        </div>
                    </div>
                    {hideNav ? (
                        // Sin menú (legitimación): el espaciador mantiene al
                        // usuario abajo, donde lo empujaba el nav
                        <div style={{ flex: 1 }} />
                    ) : (
                        <nav>
                            <NavLinks iconSize={19} />
                        </nav>
                    )}
                    <div className="nc-side-user">
                        {member?.shirt_number != null && <Kit n={member.shirt_number} size="sm" />}
                        <span>{auth.user?.name}</span>
                    </div>
                </aside>

                <div className="nc-main">
                    <header className="nc-top nc-pinstripe">
                        <Crest size={38} white />
                        <div>
                            <div className="nc-eyebrow">{eyebrow ?? t(`headers.${tab}_eyebrow`)}</div>
                            <h1 className="nc-display nc-h1">{title}</h1>
                        </div>
                        <NotificationBell />
                        {is_owner && (
                            <div className="nc-role" role="group" aria-label={t('view.switch')}>
                                <button type="button" className={!viewing_as_player ? 'on' : ''}
                                    onClick={() => viewing_as_player && switchView()}>
                                    {t('view.admin')}
                                </button>
                                <button type="button" className={viewing_as_player ? 'on' : ''}
                                    onClick={() => !viewing_as_player && switchView()}>
                                    {t('view.player')}
                                </button>
                            </div>
                        )}
                    </header>

                    <main className={`nc-body page-${tab}`}>{children}</main>

                    {/* Tabs de abajo: solo mobile */}
                    <nav className="nc-tabs">
                        <NavLinks iconSize={18} />
                    </nav>
                </div>
            </div>
        </div>
    );
}
