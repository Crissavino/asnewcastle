import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, Copy, Download } from 'lucide-react';
import AppLayout from '../Layouts/AppLayout';
import LegitimacionForm from '../Components/LegitimacionForm';
import { useTranslations } from '../i18n';

/** Vista delegado: estado del plantel, ZIP por jugador y recordatorio. */
function ManagerBoard({ roster, publicUrl }) {
    const { t } = useTranslations();
    const { flash } = usePage().props;
    const [onlyIncomplete, setOnlyIncomplete] = useState(false);
    const [reminded, setReminded] = useState(false);
    const [linkCopied, setLinkCopied] = useState(false);

    const complete = roster.filter((r) => r.status !== 'pendiente');
    const rows = onlyIncomplete ? roster.filter((r) => r.status === 'pendiente') : roster;

    const remind = () => {
        router.post(route('legitimacion.recordar'), {}, { preserveScroll: true, onSuccess: () => setReminded(true) });
    };

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(publicUrl);
        } catch {
            // sin permiso de clipboard: no hay fallback visual, el botón queda
        }
        setLinkCopied(true);
        setTimeout(() => setLinkCopied(false), 2000);
    };

    return (
        <div className="nc-admin" style={{ marginTop: 18 }}>
            <div className="nc-label">{t('legitimacion.squad_title')}</div>

            <div className="nc-row" style={{ marginTop: 10, alignItems: 'center' }}>
                <div className="nc-count">
                    <span className="n nc-num">{complete.length}</span>
                    <span className="nc-meta"> / {roster.length} {t('legitimacion.complete_count')}</span>
                </div>
                <button type="button" className={`nc-mini ${onlyIncomplete ? 'solid' : ''}`}
                    onClick={() => setOnlyIncomplete(!onlyIncomplete)}>
                    {t('legitimacion.filter_incomplete')}
                </button>
            </div>

            {/* Link público para los que todavía no tienen cuenta en la app */}
            {publicUrl && (
                <div className="nc-row" style={{ marginTop: 12, alignItems: 'center' }}>
                    <div style={{ minWidth: 0 }}>
                        <div style={{ fontSize: 13, fontWeight: 700 }}>{t('legitimacion.public_link')}</div>
                        <div className="nc-meta" style={{ fontSize: 11 }}>{t('legitimacion.public_link_hint')}</div>
                    </div>
                    <button type="button" className="nc-mini solid" onClick={copyLink} style={{ flexShrink: 0 }}>
                        {linkCopied ? <Check size={13} /> : <Copy size={13} />} {linkCopied ? t('legitimacion.iban_copied') : t('legitimacion.copy_iban')}
                    </button>
                </div>
            )}

            {rows.map((r) => (
                <div key={r.registration_id ?? `m-${r.member_id}`} style={{ borderTop: '1px solid var(--line, #DBDDD8)', marginTop: 12, paddingTop: 12 }}>
                    <div className="nc-row" style={{ alignItems: 'center' }}>
                        <div style={{ fontWeight: 700, fontSize: 14, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {r.shirt_number != null && <span className="nc-num" style={{ marginRight: 6 }}>{r.shirt_number}</span>}
                            {r.name ?? t('legitimacion.guest_unnamed')}
                            {r.guest && (
                                <span className="nc-meta" style={{ fontSize: 11, marginLeft: 6 }}>· {t('legitimacion.guest')}</span>
                            )}
                        </div>
                        <div style={{ display: 'flex', gap: 6, flexShrink: 0, alignItems: 'center' }}>
                            {r.payment_marked && (
                                <span className="nc-meta" style={{ fontSize: 11, display: 'inline-flex', alignItems: 'center', gap: 2 }}>
                                    <Check size={13} /> {t('legitimacion.paid_short')}
                                </span>
                            )}
                            {r.registration_id && r.status !== 'pendiente' && (
                                <a href={route('legitimacion.zip', r.registration_id)} className="nc-mini" style={{ textDecoration: 'none' }}>
                                    <Download size={13} /> ZIP
                                </a>
                            )}
                        </div>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, marginTop: 6 }}>
                        {r.status !== 'pendiente' ? (
                            <span className="nc-meta" style={{ fontSize: 12, color: 'var(--aqua-dk, #2b8a8f)' }}>
                                {t(`legitimacion.status_${r.status}`)}
                            </span>
                        ) : r.missing === null ? (
                            <span className="nc-meta" style={{ fontSize: 12 }}>{t('legitimacion.not_started')}</span>
                        ) : (
                            r.missing.map((key) => (
                                <span key={key} className="nc-meta" style={{ fontSize: 11, border: '1px solid var(--line, #DBDDD8)', borderRadius: 20, padding: '2px 8px' }}>
                                    {t(`legitimacion.f_${key}`)}
                                </span>
                            ))
                        )}
                    </div>
                </div>
            ))}

            <div className="nc-admin-actions" style={{ marginTop: 14 }}>
                <button type="button" className="nc-btn dark" style={{ width: '100%' }} onClick={remind}>
                    {reminded || flash.status !== null && flash.status !== undefined
                        ? t('legitimacion.reminded', { n: flash.status ?? '' })
                        : t('legitimacion.remind')}
                </button>
            </div>
        </div>
    );
}

export default function Legitimacion({ registration, missing, config, roster, public_url }) {
    const { t } = useTranslations();

    const deadline = config.daysLeft > 1
        ? t('legitimacion.deadline_banner', { days: config.daysLeft })
        : config.daysLeft === 1
            ? t('legitimacion.deadline_one')
            : config.daysLeft === 0
                ? t('legitimacion.deadline_today')
                : t('legitimacion.deadline_overdue');

    return (
        <AppLayout tab="legitimacion" hideNav eyebrow={t('legitimacion.eyebrow', { season: config.season })}>
            <div className="nc-card" style={{ background: 'var(--ink, #121212)', color: '#fff' }}>
                <div style={{ fontWeight: 700, fontSize: 14 }}>{deadline}</div>
                <p style={{ fontSize: 12, opacity: 0.75, marginTop: 4 }}>{t('legitimacion.intro')}</p>
            </div>

            <LegitimacionForm registration={registration} missing={missing} config={config}
                action={route('legitimacion.guardar')} />

            {roster && <ManagerBoard roster={roster} publicUrl={public_url} />}
        </AppLayout>
    );
}
