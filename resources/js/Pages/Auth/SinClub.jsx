import { Head, router } from '@inertiajs/react';
import Crest from '../../Components/Crest';
import { useTranslations } from '../../i18n';

export default function SinClub({ removed = false }) {
    const { t } = useTranslations();

    // Al que dieron de baja no se le ofrece pedir el link: ya lo tuvo.
    const title = removed ? t('auth.removed_title') : t('auth.no_club_title');
    const body = removed ? t('auth.removed_body') : t('auth.no_club_body');

    return (
        <div className="nc-root nc-stage">
            <Head title={title} />
            <div className="nc-app">
                <div className="nc-step" style={{ justifyContent: 'center', textAlign: 'center' }}>
                    <div style={{ display: 'flex', justifyContent: 'center' }}>
                        <Crest size={68} style={{ opacity: 0.35, filter: 'grayscale(1)' }} />
                    </div>
                    <h2 className="nc-display nc-q" style={{ marginTop: 20 }}>{title}</h2>
                    <p className="nc-meta" style={{ padding: '0 12px' }}>{body}</p>

                    <button type="button" className="nc-skip" style={{ marginTop: 28 }} onClick={() => router.post(route('salir'))}>
                        {t('auth.logout')}
                    </button>
                </div>
            </div>
        </div>
    );
}
