import { router, usePage } from '@inertiajs/react';
import { BarChart2, Check, Pencil, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { LOCALES, useTranslations } from '../i18n';

/* Doble tap: primero pregunta, después ejecuta. */
function ConfirmButton({ className, style, onConfirm, children }) {
    const { t } = useTranslations();
    const [arming, setArming] = useState(false);

    const click = () => {
        if (arming) {
            setArming(false);
            onConfirm();
        } else {
            setArming(true);
            setTimeout(() => setArming(false), 2500);
        }
    };

    return (
        <button type="button" className={className} style={style} onClick={click}>
            {arming ? t('common.sure') : children}
        </button>
    );
}

function EditSheet({ me, positions, feet, taken, maxNumber, onClose }) {
    const { t } = useTranslations();
    const [data, setData] = useState({
        first_name: me.first_name ?? '',
        last_name: me.last_name ?? '',
        position: me.position,
        preferred_foot: me.preferred_foot,
        shirt_number: me.shirt_number,
    });
    const [errors, setErrors] = useState({});

    const save = () => {
        router.patch(route('perfil.actualizar'), data, {
            onSuccess: onClose,
            onError: setErrors,
            preserveScroll: true,
        });
    };

    return (
        <div className="nc-sheet" onClick={onClose}>
            <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
                <h3 className="nc-display" style={{ fontSize: 21, margin: '5px 0 16px' }}>{t('perfil.edit')}</h3>

                <label className="nc-field-l">
                    <span className="nc-label">{t('alta.first_name_placeholder')}</span>
                    <input value={data.first_name} onChange={(e) => setData({ ...data, first_name: e.target.value })} />
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('alta.last_name_placeholder')}</span>
                    <input value={data.last_name} onChange={(e) => setData({ ...data, last_name: e.target.value })} />
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('alta.pos_q')}</span>
                    <select value={data.position} onChange={(e) => setData({ ...data, position: e.target.value })}>
                        {positions.map((p) => <option key={p} value={p}>{t(`pos.${p}`)}</option>)}
                    </select>
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('alta.foot_q')}</span>
                    <select value={data.preferred_foot} onChange={(e) => setData({ ...data, preferred_foot: e.target.value })}>
                        {feet.map((f) => <option key={f} value={f}>{t(`foot.${f}`)}</option>)}
                    </select>
                </label>

                <div className="nc-label" style={{ marginBottom: 8 }}>{t('alta.num_q')}</div>
                <div className="nc-numgrid">
                    {Array.from({ length: maxNumber }, (_, i) => i + 1).map((n) => (
                        <button
                            key={n}
                            type="button"
                            disabled={taken.includes(n)}
                            className={data.shirt_number === n ? 'on' : ''}
                            onClick={() => setData({ ...data, shirt_number: n })}
                        >
                            {n}
                        </button>
                    ))}
                </div>

                {Object.values(errors)[0] && <div className="nc-error">{Object.values(errors)[0]}</div>}

                <button className="nc-btn" style={{ marginTop: 16 }} onClick={save} disabled={data.first_name.trim().length < 2 || data.last_name.trim().length < 2}>
                    {t('agenda.save')}
                </button>
            </div>
        </div>
    );
}

export default function Perfil({ me, season, slots, positions, feet, max_number, taken, roster }) {
    const { t, locale } = useTranslations();
    const { member, flash } = usePage().props;
    const [copied, setCopied] = useState(false);
    const [editing, setEditing] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const isManager = member?.role === 'manager';

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

    const removeMember = (m) => {
        router.post(route('plantel.baja', m.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout tab="perfil">
            <div className="nc-card">
                <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
                    <Kit n={me.shirt_number} size="lg" />
                    <div style={{ flex: 1 }}>
                        <h2 className="nc-display" style={{ fontSize: 21, lineHeight: 1 }}>{me.name}</h2>
                        <div className="nc-meta" style={{ marginTop: 5 }}>
                            {t(`pos.${me.position}`)} · {t(`foot.${me.preferred_foot}`).toLowerCase()}
                        </div>
                    </div>
                    <button type="button" className="nc-mini" style={{ flex: 'none', minWidth: 0 }} onClick={() => setEditing(true)} aria-label={t('perfil.edit')}>
                        <Pencil size={13} />
                    </button>
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
                <div className="nc-label">{t('perfil.season')}</div>
                <div style={{ display: 'flex', gap: 26, marginTop: 12 }}>
                    {[
                        [t('perfil.matches'), season.matches],
                        [t('perfil.attendance'), season.attendance_pct !== null ? `${season.attendance_pct}%` : '—'],
                        [t('perfil.mvps'), season.mvps],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <div className="nc-display" style={{ fontSize: 30, lineHeight: 1 }}>{value}</div>
                            <div className="nc-label" style={{ marginTop: 3 }}>{label}</div>
                        </div>
                    ))}
                </div>
                <button className="nc-mini" style={{ marginTop: 14 }} onClick={() => router.visit(route('estadisticas'))}>
                    <BarChart2 size={13} /> {t('stats.view')}
                </button>
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
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                                <Kit n={p.shirt_number ?? '–'} size="sm" />
                                <span style={{ fontSize: 14, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.name ?? '—'}</span>
                                {p.role === 'manager' && <span className="nc-pill ok" style={{ flexShrink: 0 }}>{t('perfil.admin')}</span>}
                                {p.due_status === 'pending' && <span className="nc-pill no" style={{ flexShrink: 0 }}>{t('pill.due_owing')}</span>}
                                {p.due_status === 'paid' && <span className="nc-pill ok" style={{ flexShrink: 0, opacity: 0.7 }}>{t('pill.due_ok')}</span>}
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
                                <span className="nc-label">{p.position ?? ''}</span>
                                {isManager && (
                                    <button
                                        type="button"
                                        className="nc-mini"
                                        style={{ flex: 'none', minWidth: 0, padding: '6px 8px' }}
                                        onClick={() => router.visit(route('plantel.estadisticas', p.id))}
                                        aria-label={t('stats.view')}
                                    >
                                        <BarChart2 size={12} />
                                    </button>
                                )}
                                {isManager && p.id !== member.id && (
                                    <>
                                        <ConfirmButton
                                            className="nc-mini"
                                            style={{ flex: 'none', minWidth: 0, padding: '6px 8px', fontSize: 10, opacity: 0.75 }}
                                            onConfirm={() => router.post(route('plantel.rol', p.id), { role: p.role === 'manager' ? 'player' : 'manager' }, { preserveScroll: true })}
                                        >
                                            {p.role === 'manager' ? t('perfil.drop_admin') : t('perfil.make_admin')}
                                        </ConfirmButton>
                                        {p.role === 'player' && (
                                            <ConfirmButton
                                                className="nc-mini"
                                                style={{ flex: 'none', minWidth: 0, padding: '6px 8px', fontSize: 10 }}
                                                onConfirm={() => removeMember(p)}
                                            >
                                                <X size={12} /> {t('perfil.remove')}
                                            </ConfirmButton>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {isManager && (
                <div className="nc-card">
                    <div className="nc-label">{t('invite.title')}</div>
                    <p className="nc-meta" style={{ marginTop: 6 }}>{t('invite.hint')}</p>
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

            {/* Eliminar cuenta y datos (requisito de Google Play). Doble confirmación. */}
            {confirmingDelete ? (
                <div className="nc-card" style={{ borderColor: 'var(--red)', marginTop: 14 }}>
                    <div className="nc-strong" style={{ color: 'var(--red-dk)' }}>{t('account.delete_title')}</div>
                    <p className="nc-meta" style={{ margin: '6px 0 12px' }}>{t('account.delete_confirm')}</p>
                    <button className="nc-btn" onClick={() => router.delete(route('cuenta.eliminar'))}>
                        {t('account.delete_yes')}
                    </button>
                    <button className="nc-skip" onClick={() => setConfirmingDelete(false)}>{t('account.delete_cancel')}</button>
                </div>
            ) : (
                <button type="button" className="nc-skip" style={{ color: 'var(--red-dk)', marginTop: 4 }} onClick={() => setConfirmingDelete(true)}>
                    {t('account.delete')}
                </button>
            )}

            {editing && (
                <EditSheet
                    me={me}
                    positions={positions}
                    feet={feet}
                    taken={taken}
                    maxNumber={max_number}
                    onClose={() => setEditing(false)}
                />
            )}
        </AppLayout>
    );
}
