import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Crest from '../../Components/Crest';
import { LOCALES, useTranslations } from '../../i18n';

function detectOs() {
    if (typeof navigator === 'undefined') return 'desktop';
    const ua = navigator.userAgent || '';
    if (/iphone|ipad|ipod/i.test(ua)) return 'ios';
    if (/android/i.test(ua)) return 'android';
    return 'desktop';
}

// Un paso: círculo con número + contenido (texto y, opcional, un botón).
function Step({ n, children }) {
    return (
        <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
            <div style={{
                flexShrink: 0, width: 26, height: 26, borderRadius: '50%',
                background: 'var(--red)', color: '#fff', fontWeight: 700, fontSize: 14,
                display: 'flex', alignItems: 'center', justifyContent: 'center', marginTop: 1,
            }}>{n}</div>
            <div style={{ flex: 1, paddingTop: 2 }}>{children}</div>
        </div>
    );
}

export default function Sumate({ clubName, role, masterCode, apkUrl, testflightUrl, testflightAppStoreUrl }) {
    const { t, locale } = useTranslations();
    const [os, setOs] = useState(detectOs);
    const [copied, setCopied] = useState(false);
    const [linkCopied, setLinkCopied] = useState(false);

    // El "código" de TestFlight es la última parte del link de invitación a la beta.
    const testflightCode = (testflightUrl || '').split('/').filter(Boolean).pop();
    const roleWord = role === 'coach' ? t('sumate.role_coach') : t('sumate.role_player');

    const copyCode = async () => {
        try {
            await navigator.clipboard.writeText(masterCode);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch { /* si el navegador no deja, el código igual está a la vista */ }
    };

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(window.location.href);
            setLinkCopied(true);
            setTimeout(() => setLinkCopied(false), 1500);
        } catch { /* nada: el link igual está en la barra */ }
    };

    const changeLocale = (code) => {
        router.post(route('idioma'), { locale: code }, { preserveScroll: true });
    };

    // El paso final (login) es igual en los dos sistemas.
    const loginStep = (n) => (
        <Step n={n}>
            <div className="nc-strong">{t('sumate.step_login')}</div>
            {masterCode ? (
                <>
                    <p className="nc-meta" style={{ margin: '4px 0 8px' }}>{t('sumate.code_hint')}</p>
                    <button type="button" className="nc-code" onClick={copyCode}>
                        <span>{masterCode}</span>
                        <span className="nc-code-tag">{copied ? t('sumate.copied') : t('sumate.tap_copy')}</span>
                    </button>
                </>
            ) : (
                <p className="nc-meta" style={{ margin: '4px 0 0' }}>{t('sumate.code_whatsapp')}</p>
            )}
        </Step>
    );

    return (
        <div className="nc-root nc-stage">
            <Head title={t('sumate.title')}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <div className="nc-app">
                <div className="nc-step">
                    <div style={{ textAlign: 'center', marginBottom: 18 }}>
                        <div style={{ display: 'flex', justifyContent: 'center' }}>
                            <Crest size={64} />
                        </div>
                        <h1 className="nc-display" style={{ fontSize: 25, margin: '10px 0 0' }}>{clubName}</h1>
                        <div className="nc-label" style={{ marginTop: 4 }}>Voluntari · Ilfov · Liga a V-a</div>
                    </div>

                    <h2 className="nc-display nc-q" style={{ marginBottom: 4 }}>{t('sumate.title')}</h2>
                    <p className="nc-meta" style={{ marginBottom: 18 }}>{t('sumate.subtitle', { role: roleWord })}</p>

                    {/* Si abrieron en la compu, no sirve la guía: que lo abran en el teléfono. */}
                    {os === 'desktop' && (
                        <div className="nc-card" style={{ marginBottom: 16, borderColor: 'var(--red-dk, #9C1523)' }}>
                            <div className="nc-strong">📲 {t('sumate.desktop_title')}</div>
                            <p className="nc-meta" style={{ margin: '6px 0 10px' }}>{t('sumate.desktop_hint')}</p>
                            <button type="button" className="nc-btn" onClick={copyLink}>
                                {linkCopied ? t('sumate.copied') : t('sumate.copy_link')}
                            </button>
                        </div>
                    )}

                    {/* Selector de sistema: se autodetecta, pero se puede cambiar. */}
                    <div className="nc-os-switch" role="group" aria-label={t('sumate.choose_os')}>
                        <button type="button" className={os === 'android' ? 'on' : ''} onClick={() => setOs('android')}>
                            📱 Android
                        </button>
                        <button type="button" className={os === 'ios' ? 'on' : ''} onClick={() => setOs('ios')}>
                            🍎 iPhone
                        </button>
                    </div>

                    {os !== 'desktop' && (
                    <div className="nc-guide">
                        {os === 'android' ? (
                            <>
                                <Step n={1}>
                                    <div className="nc-strong">{t('sumate.and_step1')}</div>
                                    <a className="nc-btn" href={apkUrl} style={{ marginTop: 8, display: 'block', textAlign: 'center' }}>
                                        {t('sumate.and_download')}
                                    </a>
                                </Step>
                                <Step n={2}>
                                    <div className="nc-strong">{t('sumate.and_step2')}</div>
                                    <p className="nc-meta" style={{ margin: '4px 0 0' }}>{t('sumate.and_step2_hint')}</p>
                                </Step>
                                {loginStep(3)}
                            </>
                        ) : (
                            <>
                                <Step n={1}>
                                    <div className="nc-strong">{t('sumate.ios_step1')}</div>
                                    <a className="nc-btn ghost" href={testflightAppStoreUrl} style={{ marginTop: 8, display: 'block', textAlign: 'center' }}>
                                        {t('sumate.ios_get_testflight')}
                                    </a>
                                </Step>
                                <Step n={2}>
                                    <div className="nc-strong">{t('sumate.ios_step2')}</div>
                                    <a className="nc-btn" href={testflightUrl} style={{ marginTop: 8, display: 'block', textAlign: 'center' }}>
                                        {t('sumate.ios_join')}
                                    </a>
                                    {testflightCode && (
                                        <p className="nc-meta" style={{ margin: '6px 0 0' }}>
                                            {t('sumate.ios_code_hint', { code: testflightCode })}
                                        </p>
                                    )}
                                </Step>
                                {loginStep(3)}
                            </>
                        )}
                    </div>
                    )}

                    <div style={{ flex: 1, minHeight: 22 }} />

                    <div style={{ display: 'flex', justifyContent: 'center', gap: 14, paddingBottom: 8 }}>
                        {LOCALES.map(({ code, label }) => (
                            <button
                                key={code}
                                type="button"
                                className="nc-skip"
                                style={{ width: 'auto', padding: 8, textDecoration: locale === code ? 'none' : 'underline', color: locale === code ? 'var(--ink)' : 'var(--stone)' }}
                                onClick={() => changeLocale(code)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    <footer style={{ textAlign: 'center', fontSize: 10.5, lineHeight: 1.5, color: 'var(--stone)', opacity: 0.85, paddingTop: 4 }}>
                        ASOCIAȚIA SPORTIVĂ NEW CASTLE<br />
                        Voluntari · Ilfov · România · CIF 53035344
                    </footer>
                </div>
            </div>
        </div>
    );
}
