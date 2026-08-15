import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Crest from '../../Components/Crest';
import { LOCALES, useTranslations } from '../../i18n';

const COUNTRY_PREFIXES = {
    RO: '+40',
    AR: '+54',
    CO: '+57',
    IT: '+39',
    ES: '+34',
};

export default function Telefono({ countries }) {
    const { t, locale } = useTranslations();
    const [country, setCountry] = useState(countries[0]);
    const { data, setData, post, processing, errors, transform } = useForm({ phone: '' });

    const submit = (e) => {
        e.preventDefault();
        transform((d) => ({ phone: COUNTRY_PREFIXES[country] + d.phone.replace(/\D/g, '') }));
        post(route('otp.enviar'));
    };

    const changeLocale = (code) => {
        router.post(route('idioma'), { locale: code }, { preserveScroll: true });
    };

    return (
        <div className="nc-root">
            <Head title={t('auth.phone_title')} />
            <div className="nc-app">
                <div className="nc-step">
                    <div style={{ textAlign: 'center', marginBottom: 22 }}>
                        <div style={{ display: 'flex', justifyContent: 'center' }}>
                            <Crest size={68} />
                        </div>
                        <h1 className="nc-display" style={{ fontSize: 27, margin: '12px 0 0' }}>A.S New Castle</h1>
                        <div className="nc-label" style={{ marginTop: 4 }}>Voluntari · Ilfov · Liga a V-a</div>
                    </div>

                    <h2 className="nc-display nc-q">{t('auth.phone_title')}</h2>
                    <p className="nc-meta" style={{ marginTop: -12, marginBottom: 20 }}>{t('auth.phone_hint')}</p>

                    <form onSubmit={submit}>
                        <label className="nc-field-l">
                            <span className="nc-label">{t('auth.country')}</span>
                            <select value={country} onChange={(e) => setCountry(e.target.value)}>
                                {countries.map((c) => (
                                    <option key={c} value={c}>
                                        {c} ({COUNTRY_PREFIXES[c]})
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="nc-field-l">
                            <span className="nc-label">{t('auth.phone_label')}</span>
                            <input
                                type="tel"
                                inputMode="tel"
                                autoComplete="tel-national"
                                placeholder={t('auth.phone_placeholder')}
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                autoFocus
                            />
                        </label>

                        {errors.phone && <div className="nc-error">{errors.phone}</div>}

                        <button className="nc-btn" style={{ marginTop: 16 }} disabled={processing || data.phone.replace(/\D/g, '').length < 6}>
                            {processing ? t('auth.sending') : t('auth.send_code')}
                        </button>
                    </form>

                    <div style={{ flex: 1, minHeight: 24 }} />

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
                </div>
            </div>
        </div>
    );
}
