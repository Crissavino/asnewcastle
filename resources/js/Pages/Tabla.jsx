import { usePage } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { useTranslations } from '../i18n';

export default function Tabla({ standings }) {
    const { t } = useTranslations();
    const { club } = usePage().props;
    const rows = standings ?? [];

    return (
        <AppLayout tab="tabla" eyebrow={club?.league ?? t('headers.tabla_eyebrow')}>
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
