import { usePage } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB' };
const OUTCOME = { 1: 'w', 0: 'd', '-1': 'l' };

export default function Tabla({ standings, fixture, us, form, next }) {
    const { t, locale } = useTranslations();
    const { club, member } = usePage().props;
    const intl = INTL_LOCALES[locale] ?? 'en-GB';
    const rows = standings ?? [];
    const matches = fixture ?? [];
    const upcoming = matches.filter((m) => !m.played).slice(0, 5);

    const nextLabel = next && new Date(next.starts_at).toLocaleDateString(intl, {
        weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    });

    const matchDate = (iso) => new Date(iso).toLocaleDateString(intl, { day: 'numeric', month: 'short' });

    return (
        <AppLayout tab="tabla" eyebrow={club?.league ?? t('headers.tabla_eyebrow')}>
            {/* Nuestra campaña: posición, racha y el partido que viene */}
            {(us || form.length > 0 || next) && (
                <div className="nc-card match">
                    <div className="nc-label">{t('tabla.campaign')}</div>

                    {us && (
                        <div style={{ display: 'flex', gap: 26, marginTop: 12 }}>
                            {[
                                [t('tabla.position'), `${us.pos}°`],
                                [t('tabla.pts'), us.pts],
                                [t('tabla.pj'), us.pj],
                                [t('tabla.dg'), us.dg > 0 ? `+${us.dg}` : us.dg],
                            ].map(([label, value]) => (
                                <div key={label}>
                                    <div className="nc-display" style={{ fontSize: 28, lineHeight: 1 }}>{value}</div>
                                    <div className="nc-label" style={{ marginTop: 3 }}>{label}</div>
                                </div>
                            ))}
                        </div>
                    )}

                    {form.length > 0 && (
                        <div style={{ marginTop: 16 }}>
                            <div className="nc-label">{t('tabla.form')}</div>
                            <div className="nc-form">
                                {form.map((f) => (
                                    <i key={f.id} className={OUTCOME[f.outcome]} title={f.label}>
                                        {t(`form.${OUTCOME[f.outcome]}`)}
                                    </i>
                                ))}
                            </div>
                        </div>
                    )}

                    {next && (
                        <div className="nc-row" style={{ marginTop: 14 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                                <Kit n={member?.shirt_number} kit={next.is_home ? 'home' : 'away'} size="sm" />
                                <div style={{ minWidth: 0 }}>
                                    <div style={{ fontSize: 14, fontWeight: 700 }}>{t('tabla.next')} · vs {next.opponent}</div>
                                    <div className="nc-meta" style={{ fontSize: 12 }}>{nextLabel} · {next.venue}</div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Próximos partidos: los 5 que vienen, del fixture oficial de la liga */}
            {upcoming.length > 0 && (
                <div className="nc-card">
                    <div className="nc-label">{t('tabla.next_matches')}</div>
                    <div style={{ marginTop: 8 }}>
                        {upcoming.map((m, i) => (
                            <div key={i} className="nc-row">
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                                    <Kit n={member?.shirt_number} kit={m.is_home ? 'home' : 'away'} size="sm" />
                                    <div style={{ minWidth: 0 }}>
                                        <div style={{ fontSize: 14, fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                            vs {m.opponent}
                                        </div>
                                        <div className="nc-meta" style={{ fontSize: 12 }}>
                                            {t('tabla.round', { n: m.etapa })} · {m.is_home ? t('tabla.home_short') : t('tabla.away_short')}
                                        </div>
                                    </div>
                                </div>
                                <span className="nc-meta nc-num" style={{ fontSize: 13, flexShrink: 0, paddingLeft: 8 }}>{matchDate(m.date)}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <div className="nc-card">
                <div className="nc-label">{club?.league}</div>
                {rows.length === 0 ? (
                    <p className="nc-meta" style={{ marginTop: 10 }}>{t('empty.tabla')}</p>
                ) : (
                    <table className="nc-table" style={{ marginTop: 12 }}>
                        <thead>
                            <tr>
                                <th style={{ width: 26 }}>#</th>
                                <th>{t('tabla.team')}</th>
                                <th style={{ width: 32 }}>{t('tabla.pj')}</th>
                                <th style={{ width: 36 }}>{t('tabla.dg')}</th>
                                <th style={{ width: 34 }}>{t('tabla.pts')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.pos} className={r.us ? 'us' : ''}>
                                    <td className="nc-num" style={{ paddingLeft: 9 }}>{r.pos}</td>
                                    <td>{r.team}</td>
                                    <td className="nc-num">{r.pj}</td>
                                    <td className="nc-num">{r.dg > 0 ? `+${r.dg}` : r.dg}</td>
                                    <td className="nc-num" style={{ fontWeight: 700 }}>{r.pts}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}
