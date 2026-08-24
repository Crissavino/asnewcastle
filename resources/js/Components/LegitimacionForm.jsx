import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, Copy, FileText } from 'lucide-react';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB' };

// Los del plantel primero; abajo, todos los países (ISO 3166-1 alfa-2)
const FREQUENT = ['RO', 'AR', 'CO', 'TN', 'EG', 'IT', 'ES'];
const ALL_COUNTRIES = [
    'AD', 'AE', 'AF', 'AG', 'AL', 'AM', 'AO', 'AR', 'AT', 'AU', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI',
    'BJ', 'BN', 'BO', 'BR', 'BS', 'BT', 'BW', 'BY', 'BZ', 'CA', 'CD', 'CF', 'CG', 'CH', 'CI', 'CL', 'CM', 'CN', 'CO',
    'CR', 'CU', 'CV', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'ER', 'ES', 'ET', 'FI', 'FJ',
    'FM', 'FR', 'GA', 'GB', 'GD', 'GE', 'GH', 'GM', 'GN', 'GQ', 'GR', 'GT', 'GW', 'GY', 'HN', 'HR', 'HT', 'HU', 'ID',
    'IE', 'IL', 'IN', 'IQ', 'IR', 'IS', 'IT', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW',
    'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MG', 'MH', 'MK',
    'ML', 'MM', 'MN', 'MR', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NE', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR',
    'NZ', 'OM', 'PA', 'PE', 'PG', 'PH', 'PK', 'PL', 'PS', 'PT', 'PW', 'PY', 'QA', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB',
    'SC', 'SD', 'SE', 'SG', 'SI', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SY', 'SZ', 'TD', 'TG', 'TH',
    'TJ', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VN',
    'VU', 'WS', 'XK', 'YE', 'ZA', 'ZM', 'ZW',
];

function useCountryNames() {
    const { locale } = useTranslations();

    return useMemo(() => {
        let display = null;
        try {
            display = new Intl.DisplayNames([INTL_LOCALES[locale] ?? 'en'], { type: 'region' });
        } catch {
            // sin Intl.DisplayNames: se muestran los códigos ISO
        }
        const name = (code) => {
            try { return display?.of(code) ?? code; } catch { return code; }
        };
        const collator = new Intl.Collator(INTL_LOCALES[locale] ?? 'en');
        const rest = ALL_COUNTRIES.filter((c) => !FREQUENT.includes(c))
            .map((code) => [code, name(code)])
            .sort((a, b) => collator.compare(a[1], b[1]));

        return { frequent: FREQUENT.map((code) => [code, name(code)]), rest };
    }, [locale]);
}

const FILE_ACCEPT = 'image/*,.pdf';

/** Input de archivo con estado: subido ✓ / reemplazar / elegir. */
function FileField({ label, uploaded, file, onChange, accept = FILE_ACCEPT, error }) {
    const { t } = useTranslations();
    const id = useMemo(() => `file-${Math.random().toString(36).slice(2)}`, []);

    return (
        <div style={{ marginTop: 14 }}>
            <div className="nc-label">{label}</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 6 }}>
                <label htmlFor={id} className="nc-btn ghost" style={{ width: 'auto', padding: '0 14px', minHeight: 44, display: 'inline-flex', alignItems: 'center', cursor: 'pointer' }}>
                    <FileText size={15} style={{ marginRight: 6 }} />
                    {uploaded || file ? t('legitimacion.replace') : t('legitimacion.choose_file')}
                </label>
                <input id={id} type="file" accept={accept} style={{ display: 'none' }}
                    onChange={(e) => onChange(e.target.files[0] ?? null)} />
                {file ? (
                    <span className="nc-meta" style={{ fontSize: 12, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{file.name}</span>
                ) : uploaded ? (
                    <span className="nc-meta" style={{ fontSize: 12, color: 'var(--aqua-dk, #2b8a8f)', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                        <Check size={14} /> {t('legitimacion.uploaded')}
                    </span>
                ) : null}
            </div>
            {error && <div className="nc-meta" style={{ color: 'var(--red, #D22233)', fontSize: 12, marginTop: 4 }}>{error}</div>}
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <div style={{ marginTop: 14 }}>
            <div className="nc-label">{label}</div>
            <div style={{ marginTop: 6 }}>{children}</div>
            {error && <div className="nc-meta" style={{ color: 'var(--red, #D22233)', fontSize: 12, marginTop: 4 }}>{error}</div>}
        </div>
    );
}

/**
 * La ficha de legitimación: guarda parcial, se puede volver más tarde.
 * La usan la pantalla logueada y el formulario público (action distinto).
 */
export default function LegitimacionForm({ registration, missing, config, action }) {
    const { t } = useTranslations();
    const { errors } = usePage().props;
    const countries = useCountryNames();

    const [form, setForm] = useState({
        full_name: registration.full_name ?? '',
        birth_date: registration.birth_date ?? '',
        nationality: registration.nationality ?? '',
        cnp: registration.cnp ?? '',
        passport_number: registration.passport_number ?? '',
        previous_clubs: registration.previous_clubs ?? '',
        played_federated: registration.played_federated,
        federated_details: registration.federated_details ?? '',
        payment_marked: registration.payment_marked,
        consent: registration.consented,
    });
    const [files, setFiles] = useState({ photo: null, id_doc: null, passport: null, payment_proof: null });
    const [saving, setSaving] = useState(false);
    const [copied, setCopied] = useState(false);

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const isRO = form.nationality === 'RO';
    const complete = registration.status !== 'pendiente';

    const copyIban = async () => {
        try {
            await navigator.clipboard.writeText(config.iban.replaceAll(' ', ''));
        } catch {
            // sin permiso de clipboard: el IBAN queda visible para copiar a mano
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const submit = (e) => {
        e.preventDefault();
        const data = {};
        for (const [key, value] of Object.entries(form)) {
            if (value !== null && value !== '') data[key] = value;
        }
        // Los booleanos falsos también viajan (destildar cuenta como cambio)
        data.payment_marked = form.payment_marked ? 1 : 0;
        data.consent = form.consent ? 1 : 0;
        if (form.played_federated !== null && form.played_federated !== undefined) {
            data.played_federated = form.played_federated ? 1 : 0;
        }
        for (const [key, file] of Object.entries(files)) {
            if (file) data[key] = file;
        }

        setSaving(true);
        router.post(action, data, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setSaving(false),
            onSuccess: () => setFiles({ photo: null, id_doc: null, passport: null, payment_proof: null }),
        });
    };

    return (
        <form onSubmit={submit}>
            {/* Qué falta / ficha completa. missing null = todavía ni empezó */}
            {complete ? (
                <div className="nc-card" style={{ borderLeft: '4px solid var(--aqua, #8AD4D8)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 700 }}>
                        <Check size={18} /> {t('legitimacion.complete_badge')}
                    </div>
                    <p className="nc-meta" style={{ marginTop: 6, fontSize: 13 }}>{t('legitimacion.complete_hint')}</p>
                </div>
            ) : missing && (
                <div className="nc-card" style={{ borderLeft: '4px solid var(--red, #D22233)' }}>
                    <div className="nc-label">{t('legitimacion.missing_title')}</div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
                        {missing.map((key) => (
                            <span key={key} className="nc-mini" style={{ pointerEvents: 'none' }}>{t(`legitimacion.f_${key}`)}</span>
                        ))}
                    </div>
                </div>
            )}

            {/* Datos personales */}
            <div className="nc-card">
                <div className="nc-label">{t('legitimacion.personal_title')}</div>

                <Field label={t('legitimacion.full_name')} error={errors.full_name}>
                    <input className="nc-input" value={form.full_name} maxLength={120}
                        onChange={(e) => set('full_name', e.target.value)} />
                </Field>

                <Field label={t('legitimacion.birth_date')} error={errors.birth_date}>
                    <input className="nc-input" type="date" value={form.birth_date}
                        max={new Date().toISOString().slice(0, 10)}
                        onChange={(e) => set('birth_date', e.target.value)} />
                </Field>

                <Field label={t('legitimacion.nationality')} error={errors.nationality}>
                    <select className="nc-input" value={form.nationality}
                        onChange={(e) => set('nationality', e.target.value)}>
                        <option value="">—</option>
                        {countries.frequent.map(([code, name]) => (
                            <option key={code} value={code}>{name}</option>
                        ))}
                        <option disabled>──────────</option>
                        {countries.rest.map(([code, name]) => (
                            <option key={code} value={code}>{name}</option>
                        ))}
                    </select>
                </Field>

                {isRO && (
                    <Field label={t('legitimacion.cnp')} error={errors.cnp}>
                        <input className="nc-input nc-num" inputMode="numeric" maxLength={13}
                            placeholder="1234567890123" value={form.cnp}
                            onChange={(e) => set('cnp', e.target.value.replace(/\D/g, ''))} />
                    </Field>
                )}

                {!isRO && form.nationality && (
                    <>
                        <Field label={t('legitimacion.passport_number')} error={errors.passport_number}>
                            <input className="nc-input" maxLength={30} value={form.passport_number}
                                onChange={(e) => set('passport_number', e.target.value.toUpperCase())} />
                        </Field>
                        <FileField label={t('legitimacion.passport')} uploaded={registration.files.passport}
                            file={files.passport} error={errors.passport}
                            onChange={(f) => setFiles((x) => ({ ...x, passport: f }))} />
                    </>
                )}
            </div>

            {/* Documentos */}
            <div className="nc-card">
                <div className="nc-label">{t('legitimacion.docs_title')}</div>
                <FileField label={t('legitimacion.photo')} uploaded={registration.files.photo}
                    file={files.photo} accept="image/*" error={errors.photo}
                    onChange={(f) => setFiles((x) => ({ ...x, photo: f }))} />
                <p className="nc-meta" style={{ fontSize: 12, marginTop: 6 }}>{t('legitimacion.photo_hint')}</p>
                <FileField label={t('legitimacion.id_doc')} uploaded={registration.files.id_doc}
                    file={files.id_doc} error={errors.id_doc}
                    onChange={(f) => setFiles((x) => ({ ...x, id_doc: f }))} />
                <p className="nc-meta" style={{ fontSize: 12, marginTop: 10 }}>{t('legitimacion.file_hint')}</p>
            </div>

            {/* Historial federativo */}
            <div className="nc-card">
                <div className="nc-label">{t('legitimacion.history_title')}</div>

                <Field label={t('legitimacion.previous_clubs')} error={errors.previous_clubs}>
                    <input className="nc-input" maxLength={500} value={form.previous_clubs}
                        placeholder={t('legitimacion.previous_clubs_hint')}
                        onChange={(e) => set('previous_clubs', e.target.value)} />
                </Field>

                <Field label={t('legitimacion.played_federated')} error={errors.played_federated}>
                    <div style={{ display: 'flex', gap: 8 }}>
                        {[[true, t('common.yes')], [false, t('common.no')]].map(([value, label]) => (
                            <button key={String(value)} type="button"
                                className={`nc-opt ${form.played_federated === value ? 'on' : ''}`}
                                style={{ flex: 1 }}
                                onClick={() => set('played_federated', value)}>
                                {label}
                            </button>
                        ))}
                    </div>
                </Field>

                {form.played_federated === true && (
                    <Field label={t('legitimacion.federated_details')} error={errors.federated_details}>
                        <input className="nc-input" maxLength={500} value={form.federated_details}
                            placeholder={t('legitimacion.federated_details_hint')}
                            onChange={(e) => set('federated_details', e.target.value)} />
                    </Field>
                )}
            </div>

            {/* Pago: transferencia al club, no Stripe */}
            <div className="nc-card">
                <div className="nc-label">{t('legitimacion.payment_title')}</div>
                <p className="nc-meta" style={{ marginTop: 8, fontSize: 13 }}>{t('legitimacion.payment_info', { fee: config.fee })}</p>

                <div className="nc-row" style={{ marginTop: 10, alignItems: 'center' }}>
                    <span className="nc-num" style={{ fontSize: 13, letterSpacing: 0.5 }}>{config.iban}</span>
                    <button type="button" className="nc-mini" onClick={copyIban} style={{ flexShrink: 0 }}>
                        {copied ? <Check size={13} /> : <Copy size={13} />} {copied ? t('legitimacion.iban_copied') : t('legitimacion.copy_iban')}
                    </button>
                </div>

                <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', marginTop: 14, minHeight: 44, cursor: 'pointer' }}>
                    <input type="checkbox" checked={form.payment_marked} style={{ width: 20, height: 20, marginTop: 1 }}
                        onChange={(e) => set('payment_marked', e.target.checked)} />
                    <span style={{ fontSize: 14 }}>{t('legitimacion.payment_marked')}</span>
                </label>

                {form.payment_marked && (
                    <FileField label={t('legitimacion.payment_proof')} uploaded={registration.files.payment_proof}
                        file={files.payment_proof} error={errors.payment_proof}
                        onChange={(f) => setFiles((x) => ({ ...x, payment_proof: f }))} />
                )}
            </div>

            {/* Consentimiento */}
            <div className="nc-card">
                <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', cursor: 'pointer' }}>
                    <input type="checkbox" checked={form.consent} style={{ width: 20, height: 20, marginTop: 1, flexShrink: 0 }}
                        onChange={(e) => set('consent', e.target.checked)} />
                    <span style={{ fontSize: 13, lineHeight: 1.5 }}>{t('legitimacion.consent_text')}</span>
                </label>
            </div>

            <button type="submit" className="nc-btn dark" disabled={saving} style={{ width: '100%', marginTop: 4 }}>
                {saving ? t('legitimacion.saving') : t('legitimacion.save')}
            </button>
        </form>
    );
}
