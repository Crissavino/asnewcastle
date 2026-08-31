import { Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, ChevronUp, Copy, HelpCircle, MessageCircle, Pencil, Plus, X } from 'lucide-react';
import { IconUbicacion, IconBell } from '../Components/TabIcons';
import { useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';
import { openExternal } from '../native';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB', ar: 'ar-u-nu-latn' };

// Notas del evento con links clickeables: separa las URLs del texto y las abre
// en el navegador del sistema (no en el webview de Capacitor). Sirve para el
// link de la cancha en Maps, y cualquier otro que pongan.
const URL_RE = /(https?:\/\/[^\s]+)/g;
function linkify(text) {
    return String(text).split(URL_RE).map((part, i) =>
        /^https?:\/\//i.test(part)
            ? <a key={i} href={part} className="nc-link" onClick={(e) => { e.preventDefault(); openExternal(part); }}>{part}</a>
            : part
    );
}

function useDates() {
    const { locale } = useTranslations();
    const intl = INTL_LOCALES[locale] ?? 'en-GB';

    return {
        day: (iso) => new Date(iso).toLocaleDateString(intl, { weekday: 'long', day: 'numeric', month: 'long' }),
        time: (iso) => new Date(iso).toLocaleTimeString(intl, { hour: '2-digit', minute: '2-digit', hour12: false }),
    };
}

// El próximo sábado, que es cuando se juega
function nextSaturday() {
    const d = new Date();
    d.setDate(d.getDate() + (((6 - d.getDay() + 7) % 7) || 7));
    return d.toISOString().slice(0, 10);
}

/* Alta y edición comparten el formulario: si viene `event`, edita. */
function EventForm({ event, rosterCount, onClose }) {
    const { t } = useTranslations();
    const editing = !!event;

    const [data, setDataState] = useState(() => editing ? {
        kind: event.kind,
        opponent: event.opponent ?? '',
        is_home: event.is_home ? '1' : '0',
        date: event.starts_at.slice(0, 10),
        time: new Date(event.starts_at).toTimeString().slice(0, 5),
        venue: event.venue,
        kit: event.kit,
        notes: event.notes ?? '',
    } : {
        kind: 'match',
        opponent: '',
        is_home: '1',
        date: nextSaturday(),
        time: '11:00',
        venue: '',
        kit: 'home',
        notes: '',
    });
    const [errors, setErrors] = useState({});

    const setData = (k, v) => setDataState((p) => {
        const next = { ...p, [k]: v };
        // Cambiar el tipo ajusta la hora por defecto si no la tocaron
        if (k === 'kind' && v === 'training' && p.time === '11:00') next.time = '20:30';
        if (k === 'kind' && v === 'match' && p.time === '20:30') next.time = '11:00';
        return next;
    });

    const isMatch = data.kind === 'match';
    const ok = data.date && data.time && data.venue.trim() && (!isMatch || data.opponent.trim());

    const submit = () => {
        const payload = {
            kind: data.kind,
            opponent: isMatch ? data.opponent : null,
            is_home: isMatch ? data.is_home === '1' : null,
            starts_at: `${data.date} ${data.time}`,
            venue: data.venue,
            kit: isMatch ? data.kit : null,
            notes: data.notes || null,
        };
        const opts = { onSuccess: onClose, onError: setErrors, preserveScroll: true };

        if (editing) {
            router.put(route('eventos.actualizar', event.id), payload, opts);
        } else {
            router.post(route('eventos.crear'), payload, opts);
        }
    };

    return (
        <div className="nc-sheet" onClick={onClose}>
            <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
                <h3 className="nc-display" style={{ fontSize: 21, margin: '5px 0 16px' }}>
                    {editing ? t('agenda.edit_title') : t('agenda.new_event')}
                </h3>

                <label className="nc-field-l">
                    <span className="nc-label">{t('agenda.kind')}</span>
                    <select value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                        <option value="match">{t('agenda.kind_match')}</option>
                        <option value="training">{t('agenda.kind_training')}</option>
                    </select>
                </label>

                {isMatch && (
                    <>
                        <label className="nc-field-l">
                            <span className="nc-label">{t('agenda.rival')}</span>
                            <input value={data.opponent} onChange={(e) => setData('opponent', e.target.value)} placeholder="CS Găneasa" />
                        </label>

                        <label className="nc-field-l">
                            <span className="nc-label">{t('agenda.side')}</span>
                            <select value={data.is_home} onChange={(e) => setData('is_home', e.target.value)}>
                                <option value="1">{t('agenda.home_opt')}</option>
                                <option value="0">{t('agenda.away_opt')}</option>
                            </select>
                        </label>
                    </>
                )}

                <label className="nc-field-l">
                    <span className="nc-label">{t('agenda.when')}</span>
                    <input type="date" value={data.date} onChange={(e) => setData('date', e.target.value)} />
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('agenda.time')}</span>
                    <input type="time" value={data.time} onChange={(e) => setData('time', e.target.value)} />
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('agenda.venue')}</span>
                    <input value={data.venue} onChange={(e) => setData('venue', e.target.value)} placeholder="Teren Voluntari" />
                </label>

                {isMatch && (
                    <label className="nc-field-l">
                        <span className="nc-label">{t('agenda.kit')}</span>
                        <select value={data.kit} onChange={(e) => setData('kit', e.target.value)}>
                            <option value="home">{t('agenda.kit_home_opt')}</option>
                            <option value="away">{t('agenda.kit_away_opt')}</option>
                            <option value="both">{t('agenda.kit_both_opt')}</option>
                        </select>
                    </label>
                )}

                <label className="nc-field-l">
                    <span className="nc-label">{t('agenda.notes')}</span>
                    <input value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                </label>

                {Object.values(errors)[0] && <div className="nc-error">{Object.values(errors)[0]}</div>}

                <button className="nc-btn" style={{ marginTop: 8 }} disabled={!ok} onClick={submit}>
                    {editing ? t('agenda.update') : t('agenda.publish')}
                </button>
                {!editing && (
                    <p className="nc-meta" style={{ textAlign: 'center', marginTop: 11 }}>
                        {t('agenda.publish_hint', { count: rosterCount })}
                    </p>
                )}
            </div>
        </div>
    );
}

/* Doble tap para acciones bravas: primero pregunta, después ejecuta. */
function ConfirmButton({ className, onConfirm, children }) {
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
        <button type="button" className={className} onClick={click}>
            {arming ? t('common.sure') : children}
        </button>
    );
}

function ResultSheet({ ev, onClose }) {
    const { t } = useTranslations();
    const [gf, setGf] = useState('');
    const [ga, setGa] = useState('');
    const { club } = usePage().props;

    const save = () => {
        router.post(route('eventos.resultado', ev.id), { goals_for: gf, goals_against: ga }, {
            onSuccess: onClose,
            preserveScroll: true,
        });
    };

    return (
        <div className="nc-sheet" onClick={onClose}>
            <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
                <h3 className="nc-display" style={{ fontSize: 21, margin: '5px 0 16px' }}>{t('agenda.result_title')}</h3>
                <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end' }}>
                    <label className="nc-field-l" style={{ flex: 1 }}>
                        <span className="nc-label">{club?.name ?? t('agenda.us')}</span>
                        <input type="number" min="0" max="99" inputMode="numeric" value={gf} onChange={(e) => setGf(e.target.value)} />
                    </label>
                    <span className="nc-display" style={{ fontSize: 22, paddingBottom: 20 }}>–</span>
                    <label className="nc-field-l" style={{ flex: 1 }}>
                        <span className="nc-label">{ev.opponent}</span>
                        <input type="number" min="0" max="99" inputMode="numeric" value={ga} onChange={(e) => setGa(e.target.value)} />
                    </label>
                </div>
                <button className="nc-btn" style={{ marginTop: 8 }} disabled={gf === '' || ga === ''} onClick={save}>
                    {t('agenda.save')}
                </button>
            </div>
        </div>
    );
}

function PresentesSheet({ ev, onClose }) {
    const { t } = useTranslations();
    const [ids, setIds] = useState(ev.presence.players.filter((p) => p.present).map((p) => p.id));

    const toggle = (id) => setIds(ids.includes(id) ? ids.filter((i) => i !== id) : [...ids, id]);

    const save = () => {
        router.post(route('eventos.presentes', ev.id), { present_ids: ids }, {
            onSuccess: onClose,
            preserveScroll: true,
        });
    };

    return (
        <div className="nc-sheet" onClick={onClose}>
            <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
                <h3 className="nc-display" style={{ fontSize: 21, margin: '5px 0 4px' }}>{t('agenda.presence_title')}</h3>
                <p className="nc-meta" style={{ margin: '0 0 8px' }}>{t('agenda.presence_hint')}</p>
                <div style={{ maxHeight: '55vh', overflowY: 'auto' }}>
                    {ev.presence.players.map((p) => {
                        const on = ids.includes(p.id);
                        return (
                            <button
                                key={p.id}
                                type="button"
                                className="nc-row nc-day"
                                onClick={() => toggle(p.id)}
                                style={{ opacity: on ? 1 : 0.45 }}
                            >
                                <span style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                                    <Kit n={p.shirt_number ?? '–'} size="sm" />
                                    <span style={{ fontSize: 14, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.name ?? '—'}</span>
                                </span>
                                {on ? <Check size={15} color="var(--aqua-tx)" /> : <X size={15} style={{ opacity: 0.5 }} />}
                            </button>
                        );
                    })}
                </div>
                <button className="nc-btn" style={{ marginTop: 12 }} onClick={save}>
                    {t('agenda.save')}
                </button>
            </div>
        </div>
    );
}

function EventCard({ ev, onEdit }) {
    const { t } = useTranslations();
    const { member, invite_url } = usePage().props;
    const { day, time } = useDates();
    const [copied, setCopied] = useState(false);
    const [copiedWa, setCopiedWa] = useState(false);
    // Cuántos recibieron el último recordatorio (null = ninguno en curso)
    const [reminded, setReminded] = useState(null);
    const [showWho, setShowWho] = useState(false);
    const isManager = member?.role === 'manager';
    const isCoach = member?.role === 'coach';
    const isMatch = ev.kind === 'match';
    // Con "both" se llevan las dos casacas; las camisetas de la lista van en roja
    const kitColor = ev.kit === 'away' ? 'away' : 'home';

    const setStatus = (status) => {
        router.post(route('asistencia', ev.id), { status }, { preserveScroll: true });
    };

    // Se recuerda a los que no definieron: sin contestar + en duda. Los que
    // dijeron Voy / No voy quedan afuera.
    const toRemind = ev.counts.pending + ev.counts.maybe;

    const remind = () => {
        router.post(route('eventos.recordar', ev.id), {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                setReminded(page.props.flash?.reminded ?? toRemind);
                setTimeout(() => setReminded(null), 6000);
            },
        });
    };

    const cancelEvent = () => {
        router.post(route('eventos.cancelar', ev.id), {}, { preserveScroll: true });
    };

    const copyList = async () => {
        await navigator.clipboard.writeText(ev.convocation ?? '');
        setCopied(true);
        setTimeout(() => setCopied(false), 1600);
    };

    // Mensaje listo para pegar en el grupo de WhatsApp: tipo, lugar, hora, la
    // lista de los que van (por orden de anotación, numerada, sin camiseta) y el
    // link para sumarse (con la app cae en la agenda; sin la app, a descargarla).
    const copyForWhatsApp = async () => {
        const title = isMatch
            ? t('wa.match_title', { opponent: ev.opponent })
            : t('wa.training_title') + (ev.notes ? ` — ${ev.notes}` : '');

        const lines = [`🔴⚫ *${title}*`, ''];
        lines.push(`📅 ${day(ev.starts_at)} · ${time(ev.starts_at)}`);
        if (ev.venue) lines.push(`📍 ${ev.venue}`);
        lines.push('');
        lines.push(`✅ *${t('agenda.going_l')} (${ev.going.length})*`);
        ev.going.forEach((p, i) => lines.push(`${i + 1}. ${p.name}`));
        lines.push('');
        lines.push(`${t('agenda.wa_cta')} 👇`);
        if (invite_url) lines.push(invite_url);

        await navigator.clipboard.writeText(lines.join('\n'));
        setCopiedWa(true);
        setTimeout(() => setCopiedWa(false), 1600);
    };

    const whoList = (label, list, ghost = false) => list.length > 0 && (
        <div style={{ marginTop: 8 }}>
            <div className="nc-label">{label}</div>
            <div className="nc-namelist" style={{ opacity: ghost ? 0.6 : 1 }}>
                {list.map((p) => `${p.shirt_number} ${p.name}`).join(' · ')}
            </div>
        </div>
    );

    return (
        <div className={`nc-card ${isMatch ? (ev.is_home ? 'match' : 'away-match') : 'training'}`} style={ev.cancelled ? { opacity: 0.6 } : undefined}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 }}>
                <div>
                    <div className="nc-label" style={{ color: isMatch ? (ev.is_home ? 'var(--red)' : 'var(--aqua-tx)') : 'var(--stone)' }}>
                        {isMatch ? (ev.is_home ? t('agenda.match_home') : t('agenda.match_away')) : t('agenda.training')}
                        {ev.cancelled && <span className="nc-pill no" style={{ marginInlineStart: 8 }}>{t('agenda.cancelled')}</span>}
                    </div>
                    <h3 className="nc-display" style={{ fontSize: 19, margin: '5px 0 2px', textDecoration: ev.cancelled ? 'line-through' : 'none' }}>
                        {ev.opponent ? `vs ${ev.opponent}` : day(ev.starts_at)}
                    </h3>
                    {ev.opponent && <div className="nc-meta">{day(ev.starts_at)}</div>}
                </div>
                <div style={{ textAlign: 'end' }}>
                    <div className="nc-num nc-time" style={{ fontSize: 21, fontWeight: 700 }}>{time(ev.starts_at)}</div>
                    {isMatch && !ev.cancelled && !isCoach && (
                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 5, marginTop: 6 }}>
                            {ev.kit === 'both' ? (
                                <>
                                    <Kit n={member.shirt_number} kit="home" size="sm" />
                                    <Kit n={member.shirt_number} kit="away" size="sm" />
                                </>
                            ) : (
                                <Kit n={member.shirt_number} kit={ev.kit} size="sm" />
                            )}
                        </div>
                    )}
                </div>
            </div>

            <div className="nc-meta" style={{ marginTop: 10, display: 'flex', gap: 6 }}>
                <IconUbicacion size={13} style={{ marginTop: 3, flexShrink: 0 }} />
                <span>{ev.venue}</span>
            </div>
            {ev.notes && <div className="nc-meta" style={{ marginTop: 3 }}>{linkify(ev.notes)}</div>}

            {!ev.cancelled && (
                <>
                    {isCoach ? (
                        <div className="nc-meta" style={{ marginTop: 12 }}>{t('agenda.coach_attends')}</div>
                    ) : (
                        <div className="nc-rsvp">
                            {[
                                { k: 'in', label: t('agenda.in'), Icon: Check },
                                { k: 'maybe', label: t('agenda.maybe'), Icon: HelpCircle },
                                { k: 'out', label: t('agenda.out'), Icon: X },
                            ].map(({ k, label, Icon }) => (
                                <button key={k} className={`${k} ${ev.my_status === k ? 'on' : ''}`} onClick={() => setStatus(k)}>
                                    <Icon size={13} /> {label}
                                </button>
                            ))}
                        </div>
                    )}

                    <div className="nc-count" style={{ alignItems: 'center' }}>
                        <span className="n">{ev.counts.in}</span>
                        <span className="nc-meta" style={{ flex: 1 }}>
                            {t('agenda.confirmed')}
                            {ev.counts.maybe > 0 && ` · ${t('agenda.in_doubt', { count: ev.counts.maybe })}`}
                            {isMatch && ev.counts.in < 11 && ` · ${t('agenda.missing', { count: 11 - ev.counts.in })}`}
                        </span>
                        <button type="button" className="nc-skip" style={{ width: 'auto', padding: '4px 2px' }} onClick={() => setShowWho(!showWho)}>
                            {showWho ? <ChevronUp size={15} /> : <ChevronDown size={15} />} {showWho ? t('agenda.who_hide') : t('agenda.who')}
                        </button>
                    </div>

                    <div className="nc-kits">
                        {ev.going.map((p) => <Kit key={p.id} n={p.shirt_number} kit={kitColor} size="sm" />)}
                        {ev.maybe.map((p) => <Kit key={p.id} n={p.shirt_number} kit={kitColor} size="sm" ghost />)}
                    </div>

                    {showWho && (
                        <div style={{ marginTop: 6 }}>
                            {whoList(t('agenda.going_l'), ev.going)}
                            {whoList(t('agenda.maybe_l'), ev.maybe, true)}
                            {whoList(t('agenda.out_l'), ev.out, true)}
                            {ev.counts.pending > 0 && (
                                <div className="nc-meta" style={{ marginTop: 8 }}>{t('agenda.pending_l', { count: ev.counts.pending })}</div>
                            )}
                        </div>
                    )}
                </>
            )}

            {isManager && !ev.cancelled && (
                <div className="nc-admin">
                    <div className="nc-label">{t('agenda.convocation')}</div>
                    <div className="nc-namelist">{ev.convocation || t('agenda.nobody')}</div>
                    {invite_url && (
                        <div className="nc-admin-actions">
                            <button className="nc-mini solid" onClick={copyForWhatsApp}>
                                <MessageCircle size={13} /> {copiedWa ? t('agenda.copied') : t('agenda.copy_whatsapp')}
                            </button>
                        </div>
                    )}
                    <div className="nc-admin-actions">
                        <button className="nc-mini" onClick={copyList}>
                            <Copy size={13} /> {copied ? t('agenda.copied') : t('agenda.copy_list')}
                        </button>
                        {toRemind > 0 && (
                            <button className="nc-mini solid" onClick={remind} disabled={reminded !== null}>
                                <IconBell size={13} /> {t('agenda.remind', { count: toRemind })}
                            </button>
                        )}
                    </div>
                    {reminded !== null && (
                        <div className="nc-meta nc-reminded" role="status">
                            {t('agenda.reminded', { count: reminded })}
                        </div>
                    )}
                    <div className="nc-admin-actions">
                        <button className="nc-mini" onClick={() => onEdit(ev)}>
                            <Pencil size={13} /> {t('agenda.edit')}
                        </button>
                        <ConfirmButton className="nc-mini" onConfirm={cancelEvent}>
                            <X size={13} /> {t('agenda.cancel_event')}
                        </ConfirmButton>
                    </div>
                </div>
            )}
        </div>
    );
}

function RecentResults({ recent, onLoadResult, onLoadPresence }) {
    const { t } = useTranslations();
    const { member, club } = usePage().props;
    const { day } = useDates();
    const isManager = member?.role === 'manager';

    if (recent.length === 0) return null;

    return (
        <div className="nc-card">
            <div className="nc-label">{t('agenda.recent')}</div>
            <div style={{ marginTop: 6 }}>
                {recent.map((m) => (
                    <div key={m.id} className="nc-row" style={{ alignItems: 'center' }}>
                        <div style={{ minWidth: 0 }}>
                            <div style={{ fontSize: 14, fontWeight: 600 }}>
                                {m.is_home ? `${club?.name ?? ''} – ${m.opponent}` : `${m.opponent} – ${club?.name ?? ''}`}
                            </div>
                            <div className="nc-meta" style={{ fontSize: 11 }}>
                                {day(m.starts_at)}{m.mvp && ` · ${t('agenda.mvp_of', { name: m.mvp })}`}
                            </div>
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
                            {isManager && m.presence && (
                                <button
                                    className={`nc-mini${m.presence.confirmed ? '' : ' solid'}`}
                                    style={{ flex: 'none', minWidth: 0, padding: '6px 8px', fontSize: 10 }}
                                    onClick={() => onLoadPresence(m)}
                                >
                                    {m.presence.confirmed ? t('agenda.presence_btn') : t('agenda.presence_pending')}
                                </button>
                            )}
                            {m.result ? (
                                <span className="nc-display nc-num" style={{ fontSize: 21 }}>
                                    {m.is_home ? `${m.result.gf}–${m.result.ga}` : `${m.result.ga}–${m.result.gf}`}
                                </span>
                            ) : isManager ? (
                                <button className="nc-mini" style={{ flex: 'none' }} onClick={() => onLoadResult(m)}>
                                    {t('agenda.result_btn')}
                                </button>
                            ) : (
                                <span className="nc-meta">—</span>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Agenda({ events, recent, roster_count }) {
    const { t } = useTranslations();
    const { member } = usePage().props;
    const [formEvent, setFormEvent] = useState(null); // null cerrado · false alta · {ev} edición
    const [resultFor, setResultFor] = useState(null);
    const [presenceFor, setPresenceFor] = useState(null);
    const isManager = member?.role === 'manager';

    return (
        <AppLayout tab="agenda">
            {isManager && (
                <button
                    className="nc-btn dark"
                    style={{ marginBottom: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8 }}
                    onClick={() => setFormEvent(false)}
                >
                    <Plus size={17} /> {t('agenda.new_event')}
                </button>
            )}

            {events.length === 0 && (
                <div className="nc-card" style={{ gridColumn: '1 / -1' }}>
                    <p className="nc-meta">{t(isManager ? 'empty.agenda_manager' : 'empty.agenda')}</p>
                </div>
            )}

            {events.map((ev) => <EventCard key={ev.id} ev={ev} onEdit={(e) => setFormEvent(e)} />)}

            <RecentResults recent={recent} onLoadResult={setResultFor} onLoadPresence={setPresenceFor} />

            {events.length > 0 && (
                <p className="nc-meta" style={{ textAlign: 'center', padding: '8px 22px' }}>{t('agenda.kit_note')}</p>
            )}

            {formEvent !== null && (
                <EventForm event={formEvent || null} rosterCount={roster_count} onClose={() => setFormEvent(null)} />
            )}
            {resultFor && <ResultSheet ev={resultFor} onClose={() => setResultFor(null)} />}
            {presenceFor && <PresentesSheet ev={presenceFor} onClose={() => setPresenceFor(null)} />}
        </AppLayout>
    );
}
