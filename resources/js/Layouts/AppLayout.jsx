import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarDays, ListOrdered, MessageSquare, Wallet, User } from 'lucide-react';
import Crest from '../Components/Crest';
import { useTranslations } from '../i18n';

const TABS = [
    { key: 'agenda', route: 'agenda', Icon: CalendarDays },
    { key: 'tabla', route: 'tabla', Icon: ListOrdered },
    { key: 'vestuario', route: 'vestuario', Icon: MessageSquare },
    { key: 'cuota', route: 'cuota', Icon: Wallet },
    { key: 'perfil', route: 'perfil', Icon: User },
];

export default function AppLayout({ tab, eyebrow, children }) {
    const { t } = useTranslations();
    const { url } = usePage();

    const title = t(`tabs.${tab}`);

    return (
        <div className="nc-root">
            <Head title={title} />
            <div className="nc-app">
                <header className="nc-top nc-pinstripe">
                    <Crest size={38} />
                    <div>
                        <div className="nc-eyebrow">{eyebrow ?? t(`headers.${tab}_eyebrow`)}</div>
                        <h1 className="nc-display nc-h1">{title}</h1>
                    </div>
                </header>

                <main className="nc-body">{children}</main>

                <nav className="nc-tabs">
                    {TABS.map(({ key, route: name, Icon }) => (
                        <Link key={key} href={route(name)} className={url.startsWith(`/${name}`) ? 'on' : ''}>
                            <Icon size={18} strokeWidth={2} />
                            {t(`tabs.${key}`)}
                        </Link>
                    ))}
                </nav>
            </div>
        </div>
    );
}
