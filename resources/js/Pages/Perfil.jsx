import { router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { LOCALES, useTranslations } from '../i18n';

export default function Perfil({ me, slots, roster }) {
    const { t, locale } = useTranslations();
    const { member, flash } = usePage().props;
    const [copied, setCopied] = useState(false);

    const generateInvite = () => {
        router.post(route('invitacion.crear'), {}, { preserveScroll: true });
    };

    const copyInvite = async () => {
        await navigator.clipboard.writeText(flash.invite_url);
        setCopied(true);
        setTimeout(() => setCopied(false), 1600);
    };

    const changeLocale = (code) => {
        router.post(route('idioma'), { locale: code }, { preserveScroll: true });
    };

    const toggleDay = (day) => {
        const next = me.availability.includes(day)
            ? me.availability.filter((d) => d !== day)
            : [...me.availability, day];

        if (next.length === 0) return; // al menos un día

        router.post(route('perfil.disponibilidad'), { availability: next }, { preserveScroll: true });
    };

    return (
        <AppLayout tab="perfil">
            <div className="nc-card">
                <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
                    <Kit n={me.shirt_number} size="lg" />
                    <div>
                        <h2 className="nc-display" style={{ fontSize: 21, lineHeight: 1 }}>{me.name}</h2>
                        <div className="nc-meta" style={{ marginTop: 5 }}>
                            {t(`pos.${me.position}`)} · {t(`foot.${me.preferred_foot}`).toLowerCase()}
                        </div>
                    </div>
                </div>
                <div className="nc-row" style={{ marginTop: 14 }}>
                    <span className="nc-meta">{t('perfil.equipment')}</span>
                    <div style={{ display: 'flex', gap: 7 }}>
                        <Kit n={me.shirt_number} kit="home" size="sm" />
                        <Kit n={me.shirt_number} kit="away" size="sm" />
                    </div>
                </div>
            </div>

            <div className="nc-card">
                <div className="nc-label">{t('perfil.availability')}</div>
                <p className="nc-meta" style={{ marginTop: 6 }}>{t('perfil.availability_hint')}</p>
                <div style={{ marginTop: 4 }}>
                    {slots.map((s) => {
                        const on = me.availability.includes(s);
                        return (
                            <button
                                key={s}
                                type="button"
                                className="nc-row nc-day"
                                onClick={() => toggleDay(s)}
                                style={{ opacity: on ? 1 : 0.45 }}
                            >
                                <span style={{ fontSize: 14 }}>{t(`slot.${s}`)}</span>
                                {on ? <Check size={15} color="var(--aqua-dk)" /> : <span className="nc-meta">—</span>}
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="nc-card">
                <div className="nc-label">{t('perfil.roster')}</div>
                <div style={{ marginTop: 6 }}>
                    {roster.map((p) => (
                        <div key={p.id} className="nc-row">
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                <Kit n={p.shirt_number ?? '–'} size="sm" />
                                <span style={{ fontSize: 14 }}>{p.name ?? '—'}</span>
                            </div>
                            <span className="nc-label">{p.position ?? ''}</span>
                        </div>
                    ))}
                </div>
            </div>

            {member?.role === 'manager' && (
                <div className="nc-card">
                    <div className="nc-label">{t('invite.hint')}</div>
                    <div className="nc-admin-actions">
                        {flash.invite_url ? (
                            <button className="nc-mini solid" onClick={copyInvite}>
                                {copied ? t('invite.copied') : flash.invite_url.replace(/^https?:\/\//, '').slice(0, 34) + '…'}
                            </button>
                        ) : (
                            <button className="nc-mini" onClick={generateInvite}>
                                {t('invite.button')}
                            </button>
                        )}
                    </div>
                </div>
            )}

            <div className="nc-card">
                <div className="nc-label">{t('common.language')}</div>
                <div style={{ marginTop: 6 }}>
                    {LOCALES.map(({ code, label }) => (
                        <div key={code} className="nc-row">
                            <span style={{ fontSize: 14 }}>{label}</span>
                            <button
                                className="nc-mini"
                                style={{ flex: 'none', minWidth: 90, opacity: locale === code ? 1 : 0.55 }}
                                onClick={() => changeLocale(code)}
                                disabled={locale === code}
                            >
                                {locale === code ? '✓' : '→'}
                            </button>
                        </div>
                    ))}
                </div>
            </div>

            <button className="nc-btn ghost" onClick={() => router.post(route('salir'))}>
                {t('auth.logout')}
            </button>
        </AppLayout>
    );
}
