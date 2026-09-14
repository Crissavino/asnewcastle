import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB', ar: 'ar-u-nu-latn' };

function money(cents) {
    return cents % 100 === 0 ? String(cents / 100) : (cents / 100).toFixed(2);
}

/** Fecha local YYYY-MM-DD, sin líos de zona horaria. */
function iso(date) {
    const p = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`;
}

/**
 * Popup de cobranza: si el jugador debe cuota, al entrar a la app le muestra
 * la deuda acumulada con tres salidas — pagar ahora, comprometerse a una
 * fecha (eso silencia el aviso hasta esa fecha) o avisar en privado que no
 * puede pagar. Aparece una vez por día; si una fecha comprometida pasó y la
 * deuda sigue, vuelve con el compromiso incumplido al frente.
 */
export default function DebtPopup() {
    const { t, locale } = useTranslations();
    const { debt } = usePage().props;

    const [open, setOpen] = useState(Boolean(debt?.show_popup));
    const [view, setView] = useState('main'); // main | date | cant | sent
    const [picked, setPicked] = useState('');
    const [custom, setCustom] = useState('');
    const [reason, setReason] = useState('');
    const [sending, setSending] = useState(false);

    // Marcarlo visto (una vez por día) sin recargar props: si recargáramos,
    // show_popup pasaría a false y el popup se cerraría solo en la cara del
    // jugador. Por eso axios y no router.
    useEffect(() => {
        if (open) {
            window.axios.post(route('cuota.avisovisto')).catch(() => {});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const intl = INTL_LOCALES[locale] ?? 'es-AR';
    const fmt = (isoDate) => new Date(`${isoDate}T12:00:00`).toLocaleDateString(intl, { day: 'numeric', month: 'long' });

    // Opciones rápidas de fecha: este viernes, el día del partido, fin de mes.
    // Solo fechas de mañana en adelante y a 30 días como mucho, sin repetidas.
    const options = useMemo(() => {
        if (!debt) return [];
        const today = new Date();
        const min = new Date(today); min.setDate(min.getDate() + 1);
        const max = new Date(today); max.setDate(max.getDate() + 30);

        const friday = new Date(today);
        friday.setDate(friday.getDate() + (((5 - friday.getDay()) % 7) || 7));
        const eom = new Date(today.getFullYear(), today.getMonth() + 1, 0);

        const list = [
            { key: 'opt_friday', date: iso(friday) },
            debt.next_match_on ? { key: 'opt_match', date: debt.next_match_on } : null,
            { key: 'opt_eom', date: iso(eom) },
        ].filter(Boolean).filter((o) => o.date >= iso(min) && o.date <= iso(max));

        return list.filter((o, i) => list.findIndex((x) => x.date === o.date) === i);
    }, [debt]);

    if (!debt || !open) return null;

    const broken = debt.promise?.broken;
    const amount = `${money(debt.total_cents)} ${debt.currency}`;
    const chosenDate = picked === 'custom' ? custom : picked;

    const commit = () => {
        if (!chosenDate || sending) return;
        setSending(true);
        router.post(route('cuota.compromiso'), { promised_for: chosenDate }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setOpen(false),
            onFinish: () => setSending(false),
        });
    };

    const sendReason = () => {
        if (!reason.trim() || sending) return;
        setSending(true);
        router.post(route('cuota.nopuedo'), { reason: reason.trim() }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setView('sent'),
            onFinish: () => setSending(false),
        });
    };

    const goPay = () => {
        setOpen(false);
        if (!window.location.pathname.startsWith('/cuota')) {
            router.visit(route('cuota'));
        }
    };

    const minDate = new Date(); minDate.setDate(minDate.getDate() + 1);
    const maxDate = new Date(); maxDate.setDate(maxDate.getDate() + 30);

    return (
        <div className="nc-sheet" onClick={() => setOpen(false)}>
            <div className="nc-sheet-inner" style={broken ? { borderTopColor: 'var(--ink)' } : undefined} onClick={(e) => e.stopPropagation()}>
                <div className="nc-sheet-head">
                    <span className="nc-label" style={broken ? { color: 'var(--red-dk)' } : undefined}>
                        {broken ? t('debt.broken_title') : t('debt.title')}
                    </span>
                    <button type="button" className="nc-sheet-close" onClick={() => setOpen(false)} aria-label={t('common.close')}>✕</button>
                </div>

                {view === 'main' && (
                    <>
                        <div className="nc-display nc-num" style={{ fontSize: 40, lineHeight: 1, margin: '6px 0 2px' }}>
                            {money(debt.total_cents)} <span style={{ fontSize: 18 }}>{debt.currency}</span>
                        </div>
                        <p className="nc-meta" style={{ margin: '0 0 16px' }}>
                            {broken
                                ? t('debt.broken_body', { date: fmt(debt.promise.promised_for) })
                                : `${debt.months === 1 ? t('debt.months_one') : t('debt.months_many', { count: debt.months })} · ${t('debt.today', { date: fmt(iso(new Date())) })}`}
                        </p>
                        <button type="button" className="nc-btn" onClick={goPay}>{t('debt.pay_now')}</button>
                        <button type="button" className="nc-btn ghost" style={{ marginTop: 8 }} onClick={() => setView('date')}>
                            {broken ? t('debt.new_date') : t('debt.commit_btn')}
                        </button>
                        <button type="button" className="nc-skip" onClick={() => setView('cant')}>{t('debt.cant_pay')}</button>
                        {broken && <p className="nc-meta" style={{ margin: 0, fontSize: 11.5, textAlign: 'center' }}>{t('debt.broken_note')}</p>}
                    </>
                )}

                {view === 'date' && (
                    <>
                        <p style={{ fontSize: 15, fontWeight: 600, margin: '8px 0 12px' }}>{t('debt.when_title', { amount })}</p>
                        {options.map((o) => (
                            <button key={o.key} type="button" className={`nc-opt${picked === o.date ? ' on' : ''}`}
                                onClick={() => setPicked(o.date)}>
                                {t(`debt.${o.key}`)}
                                <span className="nc-num" style={{ fontSize: 13 }}>{fmt(o.date)}</span>
                            </button>
                        ))}
                        <button type="button" className={`nc-opt${picked === 'custom' ? ' on' : ''}`} onClick={() => setPicked('custom')}>
                            {t('debt.opt_other')}
                        </button>
                        {picked === 'custom' && (
                            <label className="nc-field-l">
                                <input type="date" value={custom} min={iso(minDate)} max={iso(maxDate)}
                                    onChange={(e) => setCustom(e.target.value)} />
                            </label>
                        )}
                        <button type="button" className="nc-btn" disabled={!chosenDate || sending} onClick={commit}>
                            {t('debt.confirm')}
                        </button>
                        <p className="nc-meta" style={{ margin: '12px 0 0', fontSize: 11.5 }}>{t('debt.truce_note')}</p>
                    </>
                )}

                {view === 'cant' && (
                    <>
                        <p style={{ fontSize: 15, fontWeight: 600, margin: '8px 0 10px' }}>{t('debt.cant_title')}</p>
                        <textarea
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder={t('debt.cant_placeholder')}
                            maxLength={500}
                            rows={4}
                            style={{ width: '100%', border: '1px solid var(--line)', borderRadius: 2, padding: '11px 12px',
                                fontFamily: "'Archivo', sans-serif", fontSize: 14, color: 'var(--ink)', resize: 'none', background: '#fff' }}
                        />
                        <button type="button" className="nc-btn dark" style={{ marginTop: 12 }} disabled={!reason.trim() || sending} onClick={sendReason}>
                            {t('debt.send')}
                        </button>
                        <p className="nc-meta" style={{ margin: '12px 0 0', fontSize: 11.5 }}>{t('debt.private_note')}</p>
                    </>
                )}

                {view === 'sent' && (
                    <>
                        <p style={{ fontSize: 15, fontWeight: 600, margin: '10px 0 16px' }}>{t('debt.sent')}</p>
                        <button type="button" className="nc-btn ghost" onClick={() => setOpen(false)}>{t('common.done')}</button>
                    </>
                )}
            </div>
        </div>
    );
}
