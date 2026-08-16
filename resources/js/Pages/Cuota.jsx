import { router, usePage } from '@inertiajs/react';
import { Bell, Shield } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB' };

function money(cents) {
    return cents % 100 === 0 ? String(cents / 100) : (cents / 100).toFixed(2);
}

export default function Cuota({ currency, stripe_ready, my_due, caja }) {
    const { t, locale } = useTranslations();
    const { member, flash, errors } = usePage().props;
    const [claimed, setClaimed] = useState(false);
    const isManager = member?.role === 'manager';
    const intl = INTL_LOCALES[locale] ?? 'en-GB';

    const periodLabel = new Date(my_due?.period ?? Date.now())
        .toLocaleDateString(intl, { month: 'long', year: 'numeric' });

    const justPaid = typeof window !== 'undefined' && window.location.search.includes('pago=ok');

    const pay = () => router.post(route('cuota.pagar', my_due.id));

    const claim = () => {
        router.post(route('cuota.reclamar'), {}, {
            preserveScroll: true,
            onSuccess: () => setClaimed(true),
        });
    };

    return (
        <AppLayout tab="cuota" eyebrow={periodLabel}>
            <div className="nc-card">
                <div className="nc-label">{t('cuota.yours')} · {periodLabel}</div>
                {my_due ? (
                    <>
                        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, margin: '10px 0 0' }}>
                            <span className="nc-display" style={{ fontSize: 42, lineHeight: 1 }}>{money(my_due.amount_cents)}</span>
                            <span className="nc-num nc-meta" style={{ fontSize: 14, fontWeight: 600 }}>
                                {t('cuota.per_month', { currency })}
                            </span>
                        </div>
                        <div style={{ marginTop: 12 }}>
                            <span className={`nc-pill ${my_due.status === 'paid' ? 'ok' : 'no'}`}>
                                {my_due.status === 'paid'
                                    ? t('cuota.paid_pill')
                                    : t('cuota.pending_pill', { date: new Date(my_due.due_date).toLocaleDateString(intl, { day: 'numeric', month: 'long' }) })}
                            </span>
                        </div>
                        <p className="nc-meta" style={{ marginTop: 14 }}>{t('cuota.covers')}</p>

                        {my_due.status === 'pending' && justPaid && (
                            <p className="nc-meta" style={{ marginTop: 10 }}>{t('cuota.processing')}</p>
                        )}

                        {my_due.status === 'pending' && !justPaid && (
                            stripe_ready ? (
                                <button className="nc-btn" style={{ marginTop: 16 }} onClick={pay}>
                                    {t('cuota.pay', { amount: money(my_due.amount_cents), currency })}
                                </button>
                            ) : (
                                <p className="nc-meta" style={{ marginTop: 10 }}>{t('cuota.not_ready')}</p>
                            )
                        )}
                    </>
                ) : (
                    <p className="nc-meta" style={{ marginTop: 10 }}>{t('cuota.none')}</p>
                )}
            </div>

            {isManager && !stripe_ready && (
                <div className="nc-card">
                    <div className="nc-label">{t('cuota.stripe_title')}</div>
                    <p className="nc-meta" style={{ marginTop: 8 }}>{t('cuota.stripe_body')}</p>
                    {errors.stripe && <div className="nc-error">{errors.stripe}</div>}
                    <button className="nc-btn dark" style={{ marginTop: 14 }} onClick={() => router.post(route('stripe.onboarding'))}>
                        {t('cuota.stripe_button')}
                    </button>
                </div>
            )}

            {isManager && caja && (
                <div className="nc-card">
                    <div className="nc-label">{t('cuota.cash')} · {periodLabel}</div>
                    <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginTop: 10 }}>
                        <span className="nc-display" style={{ fontSize: 30 }}>{money(caja.collected_cents)}</span>
                        <span className="nc-num nc-meta" style={{ fontSize: 13 }}>
                            {t('cuota.of', { total: money(caja.target_cents), currency })}
                        </span>
                    </div>
                    <div className="nc-bar">
                        <i style={{ width: `${caja.target_cents ? (caja.collected_cents / caja.target_cents) * 100 : 0}%` }} />
                    </div>
                    <div className="nc-meta" style={{ marginTop: 8 }}>
                        {t('cuota.up_to_date', { paid: caja.paid_count, total: caja.total_count })}
                    </div>

                    {caja.debtors.length > 0 && (
                        <div style={{ marginTop: 16 }}>
                            <div className="nc-label" style={{ marginBottom: 2 }}>{t('cuota.owe')}</div>
                            {caja.debtors.map((d) => (
                                <div key={d.due_id} className="nc-row">
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                                        <Kit n={d.shirt_number} size="sm" />
                                        <span style={{ fontSize: 14, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{d.name}</span>
                                    </div>
                                    <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                                        <button
                                            className="nc-mini"
                                            style={{ flex: 'none', minWidth: 0, padding: '6px 9px', fontSize: 10 }}
                                            onClick={() => router.post(route('cuota.estado', d.due_id), { status: 'paid' }, { preserveScroll: true })}
                                        >
                                            {t('cuota.cash')}
                                        </button>
                                        <button
                                            className="nc-mini"
                                            style={{ flex: 'none', minWidth: 0, padding: '6px 9px', fontSize: 10, opacity: 0.65 }}
                                            onClick={() => router.post(route('cuota.estado', d.due_id), { status: 'waived' }, { preserveScroll: true })}
                                        >
                                            {t('cuota.waive')}
                                        </button>
                                    </div>
                                </div>
                            ))}

                            <div className="nc-admin">
                                <div className="nc-meta">
                                    {t('cuota.missing_close', {
                                        amount: money(caja.target_cents - caja.collected_cents),
                                        currency,
                                    })}
                                </div>
                                <div className="nc-admin-actions">
                                    <button className="nc-mini solid" onClick={claim}>
                                        <Bell size={13} />
                                        {claimed || flash.status
                                            ? t('cuota.claimed', { count: caja.debtors.length })
                                            : t('cuota.claim', { count: caja.debtors.length })}
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            )}

            <p className="nc-meta" style={{ textAlign: 'center', padding: '0 22px', display: 'flex', gap: 6, justifyContent: 'center' }}>
                <Shield size={13} style={{ marginTop: 2, flexShrink: 0 }} />
                <span>{t('cuota.trust')}</span>
            </p>
        </AppLayout>
    );
}
