import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, Copy, Download, FileText } from 'lucide-react';
import AppLayout from '../Layouts/AppLayout';
import { useTranslations } from '../i18n';

// Nacionalidades del plantel primero; el resto, las más probables en la liga
const COUNTRIES = ['RO', 'AR', 'CO', 'IT', 'ES', 'BR', 'UY', 'PY', 'CL', 'PE', 'VE', 'EC', 'BO', 'MX', 'PT', 'FR', 'MD', 'UA', 'GB', 'DE'];

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

/** Ficha propia del jugador: guarda parcial, se puede volver más tarde. */
function OwnForm({ registration, missing, config }) {
    const { t } = useTranslations();
    const { errors } = usePage().props;

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
        router.post(route('legitimacion.guardar'), data, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setSaving(false),
            onSuccess: () => setFiles({ photo: null, id_doc: null, passport: null, payment_proof: null }),
        });
    };

    return (
        <form onSubmit={submit}>
            {/* Qué falta / ficha completa */}
            {complete ? (
                <div className="nc-card" style={{ borderLeft: '4px solid var(--aqua, #8AD4D8)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 700 }}>
                        <Check size={18} /> {t('legitimacion.complete_badge')}
                    </div>
                    <p className="nc-meta" style={{ marginTop: 6, fontSize: 13 }}>{t('legitimacion.complete_hint')}</p>
                </div>
            ) : (
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
                        {COUNTRIES.map((code) => (
                            <option key={code} value={code}>{t(`country.${code}`)}</option>
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

/** Vista delegado: estado del plantel, ZIP por jugador y recordatorio. */
function ManagerBoard({ roster }) {
    const { t } = useTranslations();
    const { flash } = usePage().props;
    const [onlyIncomplete, setOnlyIncomplete] = useState(false);
    const [reminded, setReminded] = useState(false);

    const complete = roster.filter((r) => r.status !== 'pendiente');
    const rows = onlyIncomplete ? roster.filter((r) => r.status === 'pendiente') : roster;

    const remind = () => {
        router.post(route('legitimacion.recordar'), {}, { preserveScroll: true, onSuccess: () => setReminded(true) });
    };

    return (
        <div className="nc-admin" style={{ marginTop: 18 }}>
            <div className="nc-label">{t('legitimacion.squad_title')}</div>

            <div className="nc-row" style={{ marginTop: 10, alignItems: 'center' }}>
                <div className="nc-count">
                    <span className="n nc-num">{complete.length}</span>
                    <span className="nc-meta"> / {roster.length} {t('legitimacion.complete_count')}</span>
                </div>
                <button type="button" className={`nc-mini ${onlyIncomplete ? 'solid' : ''}`}
                    onClick={() => setOnlyIncomplete(!onlyIncomplete)}>
                    {t('legitimacion.filter_incomplete')}
                </button>
            </div>

            {rows.map((r) => (
                <div key={r.member_id} style={{ borderTop: '1px solid var(--line, #DBDDD8)', marginTop: 12, paddingTop: 12 }}>
                    <div className="nc-row" style={{ alignItems: 'center' }}>
                        <div style={{ fontWeight: 700, fontSize: 14, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {r.shirt_number != null && <span className="nc-num" style={{ marginRight: 6 }}>{r.shirt_number}</span>}
                            {r.name}
                        </div>
                        <div style={{ display: 'flex', gap: 6, flexShrink: 0, alignItems: 'center' }}>
                            {r.payment_marked && (
                                <span className="nc-meta" style={{ fontSize: 11, display: 'inline-flex', alignItems: 'center', gap: 2 }}>
                                    <Check size={13} /> {t('legitimacion.paid_short')}
                                </span>
                            )}
                            {r.registration_id && r.status !== 'pendiente' && (
                                <a href={route('legitimacion.zip', r.registration_id)} className="nc-mini" style={{ textDecoration: 'none' }}>
                                    <Download size={13} /> ZIP
                                </a>
                            )}
                        </div>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, marginTop: 6 }}>
                        {r.status !== 'pendiente' ? (
                            <span className="nc-meta" style={{ fontSize: 12, color: 'var(--aqua-dk, #2b8a8f)' }}>
                                {t(`legitimacion.status_${r.status}`)}
                            </span>
                        ) : r.missing === null ? (
                            <span className="nc-meta" style={{ fontSize: 12 }}>{t('legitimacion.not_started')}</span>
                        ) : (
                            r.missing.map((key) => (
                                <span key={key} className="nc-meta" style={{ fontSize: 11, border: '1px solid var(--line, #DBDDD8)', borderRadius: 20, padding: '2px 8px' }}>
                                    {t(`legitimacion.f_${key}`)}
                                </span>
                            ))
                        )}
                    </div>
                </div>
            ))}

            <div className="nc-admin-actions" style={{ marginTop: 14 }}>
                <button type="button" className="nc-btn dark" style={{ width: '100%' }} onClick={remind}>
                    {reminded || flash.status !== null && flash.status !== undefined
                        ? t('legitimacion.reminded', { n: flash.status ?? '' })
                        : t('legitimacion.remind')}
                </button>
            </div>
        </div>
    );
}

export default function Legitimacion({ registration, missing, config, roster }) {
    const { t } = useTranslations();

    const deadline = config.daysLeft > 0
        ? t('legitimacion.deadline_banner', { days: config.daysLeft })
        : config.daysLeft === 0
            ? t('legitimacion.deadline_today')
            : t('legitimacion.deadline_overdue');

    return (
        <AppLayout tab="legitimacion" eyebrow={t('legitimacion.eyebrow', { season: config.season })}>
            <div className="nc-card" style={{ background: 'var(--ink, #121212)', color: '#fff' }}>
                <div style={{ fontWeight: 700, fontSize: 14 }}>{deadline}</div>
                <p style={{ fontSize: 12, opacity: 0.75, marginTop: 4 }}>{t('legitimacion.intro')}</p>
            </div>

            <OwnForm registration={registration} missing={missing} config={config} />

            {roster && <ManagerBoard roster={roster} />}
        </AppLayout>
    );
}
