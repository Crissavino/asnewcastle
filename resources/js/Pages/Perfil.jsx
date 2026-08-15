import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { LOCALES, useTranslations } from '../i18n';

export default function Perfil() {
    const { t, locale } = useTranslations();
    const { auth, member, flash } = usePage().props;
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

    return (
        <AppLayout tab="perfil">
            <div className="nc-card">
                <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
                    {member?.shirt_number != null && <Kit n={member.shirt_number} size="lg" />}
                    <div>
                        <h2 className="nc-display" style={{ fontSize: 21, lineHeight: 1 }}>
                            {auth.user?.name ?? '—'}
                        </h2>
                        {member?.position && <div className="nc-meta" style={{ marginTop: 5 }}>{member.position}</div>}
                    </div>
                </div>
                <p className="nc-meta" style={{ marginTop: 12 }}>{t('empty.perfil')}</p>
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
