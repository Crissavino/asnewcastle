import { Head, Link, router, useForm } from '@inertiajs/react';
import Crest from '../../Components/Crest';
import { useTranslations } from '../../i18n';

export default function Codigo({ phone_masked, master }) {
    const { t } = useTranslations();
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('otp.verificar'));
    };

    const resend = () => {
        router.post(route('otp.reenviar'), {}, { preserveScroll: true });
    };

    return (
        <div className="nc-root nc-stage">
            <Head title={t('auth.code_title')} />
            <div className="nc-app">
                <div className="nc-step">
                    <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 22 }}>
                        <Crest size={54} />
                    </div>

                    <h2 className="nc-display nc-q">{t('auth.code_title')}</h2>
                    <p className="nc-meta" style={{ marginTop: -12, marginBottom: 20 }}>
                        {t('auth.code_hint', { phone: phone_masked })}
                    </p>

                    <form onSubmit={submit}>
                        <input
                            className="nc-input nc-num"
                            style={{ letterSpacing: '.35em', textAlign: 'center' }}
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={7}
                            placeholder="••••••"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                            autoFocus
                        />

                        {errors.code && <div className="nc-error">{errors.code}</div>}

                        <button className="nc-btn" style={{ marginTop: 20 }} disabled={processing || data.code.length < 6}>
                            {processing ? t('auth.verifying') : t('auth.verify')}
                        </button>
                    </form>

                    <div style={{ flex: 1, minHeight: 24 }} />

                    {!master && (
                        <button type="button" className="nc-skip" onClick={resend}>
                            {t('auth.resend')}
                        </button>
                    )}
                    <Link href={route('entrar')} className="nc-skip" style={{ textAlign: 'center', display: 'block' }}>
                        {t('auth.change_phone')}
                    </Link>
                </div>
            </div>
        </div>
    );
}
