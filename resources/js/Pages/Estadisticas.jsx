import { router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Star, X } from 'lucide-react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB' };
const RATE_COLORS = ['var(--stone, #767B77)', 'var(--aqua-dk, #2E8288)', 'var(--red-dk, #B01C2B)'];

/* Calificación dominante de un partido: la más votada; empate gana la más alta. */
function dominant(ratings) {
    const max = Math.max(...ratings);
    if (max === 0) return null;
    return ratings.lastIndexOf(max) + 1;
}

function Tile({ label, value, sub }) {
    return (
        <div style={{ minWidth: 0 }}>
            <div className="nc-display nc-num" style={{ fontSize: 26, lineHeight: 1 }}>
                {value}
                {sub && <span style={{ fontSize: 13, opacity: 0.55 }}>{sub}</span>}
            </div>
            <div className="nc-label" style={{ marginTop: 3 }}>{label}</div>
        </div>
    );
}

export default function Estadisticas({ stats }) {
    const { t, locale } = useTranslations();
    const { club } = usePage().props;
    const intl = INTL_LOCALES[locale] ?? 'en-GB';

    const totalRatings = stats.ratings.reduce((a, b) => a + b, 0);
    const maxRating = Math.max(...stats.ratings, 1);
    const form = stats.timeline.filter((m) => m.played).slice(0, 5);

    const day = (iso) => new Date(iso).toLocaleDateString(intl, { day: 'numeric', month: 'short' });

    return (
        <AppLayout tab="perfil">
            <button
                type="button"
                className="nc-skip"
                style={{ width: 'auto', display: 'flex', alignItems: 'center', gap: 6, padding: '2px 0 10px' }}
                onClick={() => router.visit(route('perfil'))}
            >
                <ArrowLeft size={15} /> {t('stats.back')}
            </button>

            <div className="nc-card">
                <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
                    <Kit n={stats.member.shirt_number ?? '–'} size="lg" />
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div className="nc-label">{t('stats.title')}</div>
                        <h2 className="nc-display" style={{ fontSize: 21, lineHeight: 1.05, marginTop: 4 }}>
                            {stats.member.name}
                        </h2>
                        {stats.member.position && (
                            <div className="nc-meta" style={{ marginTop: 4 }}>{t(`pos.${stats.member.position}`)}</div>
                        )}
                    </div>
                </div>
            </div>

            <div className="nc-card">
                <div className="nc-label">{t('stats.season')}</div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '18px 10px', marginTop: 12 }}>
                    <Tile label={t('stats.matches')} value={stats.matches_played} sub={`/${stats.matches_total}`} />
                    <Tile label={t('stats.attendance_m')} value={stats.match_pct !== null ? `${stats.match_pct}%` : '—'} />
                    <Tile label={t('stats.streak')} value={stats.streak} />
                    <Tile label={t('stats.mvps')} value={stats.mvps} />
                    <Tile label={t('stats.mvp_votes')} value={stats.mvp_votes} />
                    <Tile label={t('stats.absences')} value={stats.absences} />
                </div>
                <div className="nc-row" style={{ marginTop: 16 }}>
                    <span className="nc-meta">{t('stats.trainings')}</span>
                    <span className="nc-num" style={{ fontSize: 14, fontWeight: 700 }}>
                        {stats.trainings_attended}/{stats.trainings_total}
                        {stats.training_pct !== null && <span style={{ opacity: 0.55 }}> · {stats.training_pct}%</span>}
                    </span>
                </div>
            </div>

            <div className="nc-card">
                <div className="nc-label">{t('stats.rating')}</div>
                <p className="nc-meta" style={{ marginTop: 6 }}>{t('stats.rating_hint')}</p>
                {totalRatings === 0 ? (
                    <p className="nc-meta" style={{ marginTop: 10 }}>—</p>
                ) : (
                    <>
                        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginTop: 10 }}>
                            <span className="nc-display nc-num" style={{ fontSize: 34, lineHeight: 1 }}>{stats.rating_avg}</span>
                            <span className="nc-meta">/ 3 · {t('stats.rating_n', { count: totalRatings })}</span>
                        </div>
                        <div style={{ marginTop: 12 }}>
                            {[1, 2, 3].map((r) => (
                                <div key={r} style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 7 }}>
                                    <span className="nc-label" style={{ width: 92, flexShrink: 0 }}>{t(`rate.${r}`)}</span>
                                    <div className="nc-poll-bar" style={{ flex: 1, margin: 0 }}>
                                        <i style={{ width: `${(stats.ratings[r - 1] / maxRating) * 100}%`, background: RATE_COLORS[r - 1], opacity: 0.75 }} />
                                    </div>
                                    <b className="nc-num" style={{ fontSize: 12, width: 22, textAlign: 'right' }}>{stats.ratings[r - 1]}</b>
                                </div>
                            ))}
                        </div>
                    </>
                )}
                {form.length > 0 && (
                    <div className="nc-row" style={{ marginTop: 14 }}>
                        <span className="nc-meta">{t('stats.form')}</span>
                        <div style={{ display: 'flex', gap: 6 }}>
                            {form.map((m) => {
                                const d = dominant(m.ratings);
                                return (
                                    <span
                                        key={m.id}
                                        title={m.opponent ?? ''}
                                        style={{
                                            width: 12, height: 12, borderRadius: '50%',
                                            background: d ? RATE_COLORS[d - 1] : 'var(--line, #DBDDD8)',
                                        }}
                                    />
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>

            <div className="nc-card">
                <div className="nc-label">{t('stats.timeline')}</div>
                {stats.timeline.length === 0 && <p className="nc-meta" style={{ marginTop: 8 }}>{t('stats.empty')}</p>}
                <div style={{ marginTop: 4 }}>
                    {stats.timeline.map((m) => (
                        <div key={m.id} className="nc-row" style={{ alignItems: 'center' }}>
                            <div style={{ minWidth: 0, flex: 1 }}>
                                <div style={{ fontSize: 13, fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                    {m.is_home ? `${club?.name ?? ''} – ${m.opponent}` : `${m.opponent} – ${club?.name ?? ''}`}
                                </div>
                                <div className="nc-meta" style={{ fontSize: 11 }}>
                                    {day(m.starts_at)}
                                    {m.result && ` · ${m.is_home ? `${m.result.gf}–${m.result.ga}` : `${m.result.ga}–${m.result.gf}`}`}
                                    {m.mvp && ` · ${t('stats.mvp_pill')}`}
                                </div>
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
                                {m.votes > 0 && (
                                    <span className="nc-meta nc-num" style={{ display: 'flex', alignItems: 'center', gap: 3 }}>
                                        <Star size={12} /> {m.votes}
                                    </span>
                                )}
                                {m.played
                                    ? <Check size={15} color="var(--aqua-dk, #2E8288)" />
                                    : <X size={15} color="var(--red-dk, #B01C2B)" style={{ opacity: 0.7 }} />}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
