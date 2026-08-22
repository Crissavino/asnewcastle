import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB' };
const POLL_MS = 20000;

/**
 * La campanita del header. El contador y las últimas notificaciones llegan
 * por el prop compartido `notifications` (lo arma HandleInertiaRequests).
 * Al abrir el panel se marcan como leídas. Mismo polling que el vestuario,
 * pero más espaciado: el contador no necesita 8s.
 */
export default function NotificationBell() {
    const { t, locale } = useTranslations();
    const { notifications } = usePage().props;
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    const intl = INTL_LOCALES[locale] ?? 'en-GB';

    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['notifications'] });
        }, POLL_MS);
        return () => clearInterval(id);
    }, []);

    // Cerrar el panel al tocar afuera
    useEffect(() => {
        if (!open) return undefined;
        const onDown = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('pointerdown', onDown);
        return () => document.removeEventListener('pointerdown', onDown);
    }, [open]);

    if (!notifications) return null;
    const { unread = 0, items = [] } = notifications;

    const toggle = () => {
        const next = !open;
        setOpen(next);
        if (next && unread > 0) {
            router.post(route('notificaciones.leidas'), {}, { preserveScroll: true, preserveState: true });
        }
    };

    const rel = new Intl.RelativeTimeFormat(intl, { numeric: 'auto' });
    const timeAgo = (iso) => {
        const secs = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
        if (secs < 60) return t('notifications.now');
        const mins = Math.round(secs / 60);
        if (mins < 60) return rel.format(-mins, 'minute');
        const hours = Math.round(mins / 60);
        if (hours < 24) return rel.format(-hours, 'hour');
        return rel.format(-Math.round(hours / 24), 'day');
    };

    // Igual que el vestuario: fecha/período se formatean en el idioma activo
    const lineOf = (n) => {
        const params = { ...(n.params ?? {}) };
        if (params.date) {
            params.date = new Date(params.date).toLocaleDateString(intl, {
                weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
            });
        }
        if (params.period) {
            params.period = new Date(params.period).toLocaleDateString(intl, { month: 'long' });
        }
        return t(n.key, params);
    };

    return (
        <div className="nc-bell" ref={ref}>
            <button type="button" className="nc-bell-btn" onClick={toggle} aria-label={t('notifications.title')}>
                <Bell size={20} strokeWidth={2} />
                {unread > 0 && <b className="nc-bell-badge nc-num">{unread > 9 ? '9+' : unread}</b>}
            </button>

            {open && (
                <div className="nc-notif-panel">
                    <div className="nc-notif-head">{t('notifications.title')}</div>
                    <div className="nc-notif-list">
                        {items.length === 0 ? (
                            <p className="nc-notif-empty">{t('notifications.empty')}</p>
                        ) : (
                            items.map((n) => (
                                <Link
                                    key={n.id}
                                    href={n.url}
                                    className={`nc-notif-item ${n.read ? '' : 'unread'}`}
                                    onClick={() => setOpen(false)}
                                >
                                    <span className="nc-notif-text">{lineOf(n)}</span>
                                    <span className="nc-notif-time">{timeAgo(n.at)}</span>
                                </Link>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
