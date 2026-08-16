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

function ExpenseSheet({ categorias, eventos, currency, onClose }) {
    const { t } = useTranslations();
    const [data, setData] = useState({
        category: 'referee',
        amount: '',
        spent_on: new Date().toISOString().slice(0, 10),
        description: '',
        event_id: '',
    });

    const save = () => {
        router.post(route('gastos.crear'), {
            category: data.category,
            amount_cents: Math.round(parseFloat(data.amount) * 100),
            spent_on: data.spent_on,
            description: data.description || null,
            event_id: data.event_id || null,
        }, { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <div className="nc-sheet" onClick={onClose}>
            <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
                <h3 className="nc-display" style={{ fontSize: 21, margin: '5px 0 16px' }}>{t('cuota.expense_title')}</h3>

                <label className="nc-field-l">
                    <span className="nc-label">{t('cuota.category')}</span>
                    <select value={data.category} onChange={(e) => setData({ ...data, category: e.target.value })}>
                        {categorias.map((c) => <option key={c} value={c}>{t(`exp.${c}`)}</option>)}
                    </select>
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('cuota.amount')} ({currency})</span>
                    <input type="number" min="0" step="0.01" inputMode="decimal" value={data.amount}
                        onChange={(e) => setData({ ...data, amount: e.target.value })} />
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('cuota.date')}</span>
                    <input type="date" value={data.spent_on} onChange={(e) => setData({ ...data, spent_on: e.target.value })} />
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('cuota.event_link')}</span>
                    <select value={data.event_id} onChange={(e) => setData({ ...data, event_id: e.target.value })}>
                        <option value="">—</option>
                        {eventos.map((e) => (
                            <option key={e.id} value={e.id}>
                                {(e.opponent ? `vs ${e.opponent}` : t('agenda.kind_training'))} · {e.date}
                            </option>
                        ))}
                    </select>
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('cuota.desc')}</span>
                    <input value={data.description} onChange={(e) => setData({ ...data, description: e.target.value })} maxLength={120} />
                </label>

                <button className="nc-btn" style={{ marginTop: 8 }} disabled={!data.amount || parseFloat(data.amount) <= 0} onClick={save}>
                    {t('agenda.save')}
                </button>
            </div>
        </div>
    );
}

function ConfirmMini({ onConfirm, children, style }) {
    const { t } = useTranslations();
    const [arming, setArming] = useState(false);

    return (
        <button
            type="button"
            className="nc-mini"
            style={{ flex: 'none', minWidth: 0, padding: '6px 8px', fontSize: 10, ...style }}
            onClick={() => {
                if (arming) { setArming(false); onConfirm(); }
                else { setArming(true); setTimeout(() => setArming(false), 2500); }
            }}
        >
            {arming ? t('common.sure') : children}
        </button>
    );
}

function FeeSettings({ config, currency }) {
    const { t } = useTranslations();
    const [fee, setFee] = useState(String(config.monthly_fee_cents / 100));
    const [savedFee, setSavedFee] = useState(false);

    const saveFee = () => {
        router.patch(route('cuota.config'), {
            monthly_fee_cents: Math.round(parseFloat(fee) * 100),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSavedFee(true);
                setTimeout(() => setSavedFee(false), 1600);
            },
        });
    };

    const setType = (member, type, customCents = null) => {
        router.post(route('plantel.cuota', member.id), {
            fee_type: type,
            custom_fee_cents: customCents,
        }, { preserveScroll: true });
    };

    return (
        <div className="nc-card">
            <div className="nc-label">{t('cuota.settings')}</div>

            {/* div, NO label: un <button> dentro de <label> redirige su click
                al input y nunca dispara el onClick (por eso no guardaba). */}
            <div className="nc-field-l" style={{ marginTop: 10 }}>
                <span className="nc-label">{t('cuota.monthly_fee', { currency })}</span>
                <div style={{ display: 'flex', gap: 8, marginTop: 5 }}>
                    <input type="number" min="0" step="1" inputMode="decimal" value={fee}
                        onChange={(e) => setFee(e.target.value)} style={{ flex: 1, marginTop: 0 }} />
                    <button type="button" className="nc-mini solid" style={{ flex: 'none', minWidth: 90 }}
                        onClick={saveFee} disabled={fee === '' || parseFloat(fee) < 0}>
                        {savedFee ? `✓ ${t('cuota.saved')}` : t('agenda.save')}
                    </button>
                </div>
            </div>

            <div className="nc-label" style={{ marginTop: 14 }}>{t('cuota.fee_member')}</div>
            <p className="nc-meta" style={{ marginTop: 4 }}>{t('cuota.fee_privacy')}</p>

            <div style={{ marginTop: 4 }}>
                {config.members.map((m) => (
                    <div key={m.id} className="nc-row" style={{ gap: 8 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0, flex: 1 }}>
                            <Kit n={m.shirt_number} size="sm" />
                            <span style={{ fontSize: 14, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.name}</span>
                        </div>
                        <div style={{ display: 'flex', gap: 6, flexShrink: 0, alignItems: 'center' }}>
                            {m.fee_type === 'custom' && (
                                <input
                                    type="number" min="1" inputMode="decimal"
                                    defaultValue={m.custom_fee_cents ? m.custom_fee_cents / 100 : ''}
                                    onBlur={(e) => {
                                        const v = parseFloat(e.target.value);
                                        if (v > 0 && Math.round(v * 100) !== m.custom_fee_cents) {
                                            setType(m, 'custom', Math.round(v * 100));
                                        }
                                    }}
                                    style={{ width: 74, padding: '8px 8px', border: '1px solid var(--line)', borderRadius: 2, fontFamily: 'Rubik, sans-serif', fontSize: 13 }}
                                />
                            )}
                            <select
                                value={m.fee_type}
                                onChange={(e) => setType(m, e.target.value, e.target.value === 'custom' ? (m.custom_fee_cents ?? config.monthly_fee_cents) : null)}
                                style={{ padding: '8px 6px', border: '1px solid var(--line)', borderRadius: 2, fontFamily: 'Archivo, sans-serif', fontSize: 12, background: '#fff' }}
                            >
                                <option value="normal">{t('fee.normal')}</option>
                                <option value="becado">{t('fee.becado')}</option>
                                <option value="custom">{t('fee.custom')}</option>
                            </select>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Cuota({ currency, stripe_ready, my_due, caja, plantel, resumen, gastos, categorias, eventos, config }) {
    const { t, locale } = useTranslations();
    const { member, flash, errors } = usePage().props;
    const [claimed, setClaimed] = useState(false);
    const [addingExpense, setAddingExpense] = useState(false);
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

            {/* Caja transparente: saldo y movimientos, para todo el plantel */}
            {resumen && (
                <div className="nc-card">
                    <div className="nc-label">{t('cuota.balance')}</div>
                    <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginTop: 10 }}>
                        <span className="nc-display" style={{ fontSize: 34, color: resumen.balance_cents < 0 ? 'var(--red-dk)' : 'inherit' }}>
                            {money(resumen.balance_cents)}
                        </span>
                        <span className="nc-num nc-meta" style={{ fontSize: 13 }}>{currency}</span>
                    </div>
                    <div className="nc-row" style={{ marginTop: 12 }}>
                        <span className="nc-meta">{t('cuota.month_in')}</span>
                        <span className="nc-num" style={{ fontSize: 14, color: 'var(--aqua-dk)', fontWeight: 700 }}>
                            +{money(resumen.month_in_cents)} {currency}
                        </span>
                    </div>
                    <div className="nc-row">
                        <span className="nc-meta">{t('cuota.month_out')}</span>
                        <span className="nc-num" style={{ fontSize: 14, color: 'var(--red-dk)', fontWeight: 700 }}>
                            −{money(resumen.month_out_cents)} {currency}
                        </span>
                    </div>
                    {Object.entries(resumen.by_category).map(([cat, cents]) => (
                        <div key={cat} className="nc-row" style={{ paddingLeft: 12 }}>
                            <span className="nc-meta" style={{ fontSize: 12 }}>{t(`exp.${cat}`)}</span>
                            <span className="nc-num nc-meta" style={{ fontSize: 12 }}>{money(cents)} {currency}</span>
                        </div>
                    ))}
                </div>
            )}

            {/* Quién está al día y quién debe: lo ve todo el plantel */}
            {plantel && plantel.length > 0 && (
                <div className="nc-card">
                    <div className="nc-label">{t('cuota.squad_status')}</div>
                    {['paid', 'pending'].map((status) => {
                        const group = plantel.filter((p) => p.due_status === status);
                        if (group.length === 0) return null;
                        return (
                            <div key={status} style={{ marginTop: 12 }}>
                                <span className={`nc-pill ${status === 'paid' ? 'ok' : 'no'}`}>
                                    {status === 'paid' ? t('cuota.up') : t('cuota.owing')} · {group.length}
                                </span>
                                <div className="nc-kits" style={{ marginTop: 9 }}>
                                    {group.map((p) => (
                                        <div key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 6, marginRight: 10 }}>
                                            <Kit n={p.shirt_number} size="sm" ghost={status === 'pending'} />
                                            <span style={{ fontSize: 12 }}>{(p.name ?? '').split(' ')[0]}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Gastos del mes: los carga y borra el delegado */}
            {isManager && gastos && (
                <div className="nc-card">
                    <div className="nc-label">{t('cuota.expenses')}</div>
                    {gastos.length === 0 ? (
                        <p className="nc-meta" style={{ marginTop: 8 }}>{t('cuota.no_expenses')}</p>
                    ) : (
                        <div style={{ marginTop: 6 }}>
                            {gastos.map((g) => (
                                <div key={g.id} className="nc-row">
                                    <div style={{ minWidth: 0 }}>
                                        <span style={{ fontSize: 14 }}>{t(`exp.${g.category}`)}</span>
                                        <span className="nc-meta" style={{ fontSize: 12, marginLeft: 6 }}>
                                            {g.event ? `vs ${g.event}` : g.description ?? ''}
                                        </span>
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
                                        <span className="nc-num" style={{ fontSize: 13, fontWeight: 700 }}>{money(g.amount_cents)} {currency}</span>
                                        <ConfirmMini onConfirm={() => router.delete(route('gastos.borrar', g.id), { preserveScroll: true })}>
                                            ✕
                                        </ConfirmMini>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    <button className="nc-btn dark" style={{ marginTop: 14 }} onClick={() => setAddingExpense(true)}>
                        {t('cuota.add_expense')}
                    </button>
                </div>
            )}

            {isManager && config && <FeeSettings config={config} currency={currency} />}

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

                    {/* Histórico de cuotas: cobrado vs adeudado en todos los meses */}
                    <div className="nc-admin" style={{ marginTop: 14 }}>
                        <div className="nc-label" style={{ marginBottom: 2 }}>{t('cuota.all_time')}</div>
                        <div className="nc-row">
                            <span className="nc-meta">{t('cuota.total_paid')}</span>
                            <span className="nc-num" style={{ fontSize: 14, color: 'var(--aqua-dk)', fontWeight: 700 }}>
                                {money(caja.paid_all_cents)} {currency}
                            </span>
                        </div>
                        <div className="nc-row">
                            <span className="nc-meta">{t('cuota.total_owed')}</span>
                            <span className="nc-num" style={{ fontSize: 14, color: caja.owed_all_cents > 0 ? 'var(--red-dk)' : 'inherit', fontWeight: 700 }}>
                                {money(caja.owed_all_cents)} {currency}
                            </span>
                        </div>
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
                                            {t('cuota.mark_cash')}
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

            {addingExpense && (
                <ExpenseSheet
                    categorias={categorias}
                    eventos={eventos}
                    currency={currency}
                    onClose={() => setAddingExpense(false)}
                />
            )}
        </AppLayout>
    );
}
