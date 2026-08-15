import { router, useForm, usePage } from '@inertiajs/react';
import { Bell, Check, Copy, HelpCircle, MapPin, Plus, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB' };

function useDates() {
    const { locale } = useTranslations();
    const intl = INTL_LOCALES[locale] ?? 'en-GB';

    return {
        day: (iso) => new Date(iso).toLocaleDateString(intl, { weekday: 'long', day: 'numeric', month: 'long' }),
        time: (iso) => new Date(iso).toLocaleTimeString(intl, { hour: '2-digit', minute: '2-digit', hour12: false }),
    };
}

function NuevoEvento({ rosterCount, onClose }) {
    const { t } = useTranslations();
    const { data, setData, post, processing, errors } = useForm({
        kind: 'match',
        opponent: '',
        date: '',
        time: '11:00',
        venue: '',
        kit: 'home',
        notes: '',
    });

    const ok = data.date && data.time && data.venue.trim() && (data.kind === 'training' || data.opponent.trim());

    const submit = () => {
        router.post(route('eventos.crear'), {
            kind: data.kind,
            opponent: data.opponent,
            starts_at: `${data.date} ${data.time}`,
            venue: data.venue,
            kit: data.kit,
            notes: data.notes || null,
        }, {
            onSuccess: onClose,
            preserveScroll: true,
        });
    };

    return (
        <div className="nc-sheet" onClick={onClose}>
            <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
                <h3 className="nc-display" style={{ fontSize: 21, margin: '5px 0 16px' }}>{t('agenda.new_event')}</h3>

                <label className="nc-field-l">
                    <span className="nc-label">{t('agenda.kind')}</span>
                    <select value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                        <option value="match">{t('agenda.kind_match')}</option>
                        <option value="training">{t('agenda.kind_training')}</option>
                    </select>
                </label>

                {data.kind === 'match' && (
                    <label className="nc-field-l">
                        <span className="nc-label">{t('agenda.rival')}</span>
                        <input value={data.opponent} onChange={(e) => setData('opponent', e.target.value)} placeholder="CS Găneasa" />
                    </label>
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

                <label className="nc-field-l">
                    <span className="nc-label">{t('agenda.kit')}</span>
                    <select value={data.kit} onChange={(e) => setData('kit', e.target.value)}>
                        <option value="home">{t('agenda.kit_home_opt')}</option>
                        <option value="away">{t('agenda.kit_away_opt')}</option>
                    </select>
                </label>

                <label className="nc-field-l">
                    <span className="nc-label">{t('agenda.notes')}</span>
                    <input value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                </label>

                {Object.values(errors)[0] && <div className="nc-error">{Object.values(errors)[0]}</div>}

                <button className="nc-btn" style={{ marginTop: 8 }} disabled={!ok || processing} onClick={submit}>
                    {t('agenda.publish')}
                </button>
                <p className="nc-meta" style={{ textAlign: 'center', marginTop: 11 }}>
                    {t('agenda.publish_hint', { count: rosterCount })}
                </p>
            </div>
        </div>
    );
}

function EventCard({ ev }) {
    const { t } = useTranslations();
    const { member } = usePage().props;
    const { day, time } = useDates();
    const [copied, setCopied] = useState(false);
    const isManager = member?.role === 'manager';
    const isMatch = ev.kind === 'match';

    const setStatus = (status) => {
        router.post(route('asistencia', ev.id), { status }, { preserveScroll: true });
    };

    const remind = () => {
        router.post(route('eventos.recordar', ev.id), {}, { preserveScroll: true });
    };

    const copyList = async () => {
        await navigator.clipboard.writeText(ev.convocation ?? '');
        setCopied(true);
        setTimeout(() => setCopied(false), 1600);
    };

    return (
        <div className={`nc-card ${isMatch ? (ev.kit === 'away' ? 'away-match' : 'match') : ''}`}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 }}>
                <div>
                    <div className="nc-label" style={{ color: isMatch ? (ev.kit === 'away' ? 'var(--aqua-dk)' : 'var(--red)') : 'var(--stone)' }}>
                        {isMatch ? (ev.is_home ? t('agenda.match_home') : t('agenda.match_away')) : t('agenda.training')}
                    </div>
                    <h3 className="nc-display" style={{ fontSize: 19, margin: '5px 0 2px' }}>
                        {ev.opponent ? `vs ${ev.opponent}` : day(ev.starts_at)}
                    </h3>
                    {ev.opponent && <div className="nc-meta">{day(ev.starts_at)}</div>}
                </div>
                <div style={{ textAlign: 'right' }}>
                    <div className="nc-num" style={{ fontSize: 19, fontWeight: 700 }}>{time(ev.starts_at)}</div>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 6 }}>
                        <Kit n={member.shirt_number} kit={ev.kit} size="sm" />
                    </div>
                </div>
            </div>

            <div className="nc-meta" style={{ marginTop: 10, display: 'flex', gap: 6 }}>
                <MapPin size={13} style={{ marginTop: 3, flexShrink: 0 }} />
                <span>{ev.venue}</span>
            </div>
            {ev.notes && <div className="nc-meta" style={{ marginTop: 3 }}>{ev.notes}</div>}

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

            <div className="nc-count">
                <span className="n">{ev.counts.in}</span>
                <span className="nc-meta">
                    {t('agenda.confirmed')}
                    {ev.counts.maybe > 0 && ` · ${t('agenda.in_doubt', { count: ev.counts.maybe })}`}
                    {isMatch && ev.counts.in < 11 && ` · ${t('agenda.missing', { count: 11 - ev.counts.in })}`}
                </span>
            </div>

            <div className="nc-kits">
                {ev.going.map((p) => <Kit key={p.id} n={p.shirt_number} kit={ev.kit} size="sm" />)}
                {ev.maybe.map((p) => <Kit key={p.id} n={p.shirt_number} kit={ev.kit} size="sm" ghost />)}
            </div>

            {isManager && (
                <div className="nc-admin">
                    <div className="nc-label">{t('agenda.convocation')}</div>
                    <div className="nc-namelist">{ev.convocation || t('agenda.nobody')}</div>
                    {(ev.counts.maybe > 0 || ev.counts.out > 0 || ev.counts.pending > 0) && (
                        <div className="nc-meta" style={{ marginTop: 8 }}>
                            {t('agenda.status_line', { maybe: ev.counts.maybe, out: ev.counts.out, pending: ev.counts.pending })}
                        </div>
                    )}
                    <div className="nc-admin-actions">
                        <button className="nc-mini" onClick={copyList}>
                            <Copy size={13} /> {copied ? t('agenda.copied') : t('agenda.copy_list')}
                        </button>
                        {ev.counts.pending > 0 && (
                            <button className="nc-mini solid" onClick={remind}>
                                <Bell size={13} /> {t('agenda.remind', { count: ev.counts.pending })}
                            </button>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Agenda({ events, roster_count }) {
    const { t } = useTranslations();
    const { member } = usePage().props;
    const [nuevo, setNuevo] = useState(false);
    const isManager = member?.role === 'manager';

    return (
        <AppLayout tab="agenda">
            {isManager && (
                <button
                    className="nc-btn dark"
                    style={{ marginBottom: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8 }}
                    onClick={() => setNuevo(true)}
                >
                    <Plus size={17} /> {t('agenda.new_event')}
                </button>
            )}

            {events.length === 0 && (
                <div className="nc-card">
                    <p className="nc-meta">{t('empty.agenda')}</p>
                </div>
            )}

            {events.map((ev) => <EventCard key={ev.id} ev={ev} />)}

            {events.length > 0 && (
                <p className="nc-meta" style={{ textAlign: 'center', padding: '8px 22px' }}>{t('agenda.kit_note')}</p>
            )}

            {nuevo && <NuevoEvento rosterCount={roster_count} onClose={() => setNuevo(false)} />}
        </AppLayout>
    );
}
