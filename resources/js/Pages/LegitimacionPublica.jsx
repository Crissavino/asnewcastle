import { Head, router } from '@inertiajs/react';
import Crest from '../Components/Crest';
import LegitimacionForm from '../Components/LegitimacionForm';
import { useTranslations } from '../i18n';

/**
 * Formulario público de legitimación: se entra por el link firmado que
 * comparte el delegado, sin login. No hay navegación a nada más de la
 * app; solo el formulario y el selector inglés/rumano.
 */
export default function LegitimacionPublica({ club, registration, missing, config }) {
    const { t, locale } = useTranslations();

    const setLocale = (code) => {
        if (code !== locale) router.post(route('idioma'), { locale: code }, { preserveScroll: true });
    };

    const deadline = config.daysLeft > 1
        ? t('legitimacion.deadline_banner', { days: config.daysLeft })
        : config.daysLeft === 1
            ? t('legitimacion.deadline_one')
            : config.daysLeft === 0
                ? t('legitimacion.deadline_today')
                : t('legitimacion.deadline_overdue');

    return (
        <div className="nc-root">
            <Head title={`${t('tabs.legitimacion')} — ${club.name}`} />
            <div className="nc-shell">
                <div className="nc-main">
                    <header className="nc-top nc-pinstripe">
                        <Crest size={38} white />
                        <div style={{ minWidth: 0 }}>
                            <div className="nc-eyebrow">{club.name}</div>
                            <h1 className="nc-display nc-h1">{t('tabs.legitimacion')}</h1>
                        </div>
                        {/* Selector de idioma: el link se comparte con jugadores
                            nuevos, que hablan inglés o rumano */}
                        <div className="nc-role" role="group" aria-label="Language" style={{ marginLeft: 'auto' }}>
                            <button type="button" className={locale === 'en' ? 'on' : ''} onClick={() => setLocale('en')}>
                                EN
                            </button>
                            <button type="button" className={locale === 'ro' ? 'on' : ''} onClick={() => setLocale('ro')}>
                                RO
                            </button>
                        </div>
                    </header>

                    <main className="nc-body page-legitimacion">
                        <div className="nc-card" style={{ background: 'var(--ink, #121212)', color: '#fff' }}>
                            <div style={{ fontWeight: 700, fontSize: 14 }}>{deadline}</div>
                            <p style={{ fontSize: 12, opacity: 0.75, marginTop: 4 }}>{t('legitimacion.public_intro', { club: club.name })}</p>
                        </div>

                        <LegitimacionForm registration={registration} missing={missing} config={config}
                            action={route('legitimacion.publica.guardar')} />
                    </main>
                </div>
            </div>
        </div>
    );
}
