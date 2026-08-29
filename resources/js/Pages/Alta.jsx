import { Head, useForm } from '@inertiajs/react';
import { Check, ChevronLeft } from 'lucide-react';
import { useState } from 'react';
import Crest from '../Components/Crest';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';

export default function Alta({ taken, positions, feet, slots, max_number, first_name, last_name, role }) {
    const { t } = useTranslations();
    const [step, setStep] = useState(0);
    const { data, setData, post, processing, errors } = useForm({
        first_name: first_name ?? '',
        last_name: last_name ?? '',
        position: '',
        preferred_foot: '',
        shirt_number: null,
        availability: [],
    });

    const toggleSlot = (s) =>
        setData('availability', data.availability.includes(s)
            ? data.availability.filter((x) => x !== s)
            : [...data.availability, s]);

    const allSteps = [
        {
            q: t('alta.name_q'),
            hint: t('alta.name_hint'),
            ok: data.first_name.trim().length > 1 && data.last_name.trim().length > 1,
            error: errors.first_name || errors.last_name,
            body: (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    <input
                        className="nc-input"
                        autoFocus
                        placeholder={t('alta.first_name_placeholder')}
                        value={data.first_name}
                        onChange={(e) => setData('first_name', e.target.value)}
                    />
                    <input
                        className="nc-input"
                        placeholder={t('alta.last_name_placeholder')}
                        value={data.last_name}
                        onChange={(e) => setData('last_name', e.target.value)}
                    />
                </div>
            ),
        },
        {
            q: t('alta.pos_q'),
            hint: t('alta.pos_hint'),
            ok: !!data.position,
            error: errors.position,
            body: positions.map((p) => (
                <button key={p} type="button" className={`nc-opt ${data.position === p ? 'on' : ''}`} onClick={() => setData('position', p)}>
                    {t(`pos.${p}`)}
                    <span className="nc-num" style={{ fontSize: 11, opacity: 0.55 }}>{p}</span>
                </button>
            )),
        },
        {
            q: t('alta.foot_q'),
            ok: !!data.preferred_foot,
            error: errors.preferred_foot,
            body: feet.map((f) => (
                <button key={f} type="button" className={`nc-opt ${data.preferred_foot === f ? 'on' : ''}`} onClick={() => setData('preferred_foot', f)}>
                    {t(`foot.${f}`)}
                </button>
            )),
        },
        {
            q: t('alta.num_q'),
            hint: t('alta.num_hint'),
            ok: data.shirt_number !== null,
            error: errors.shirt_number,
            body: (
                <div className="nc-numgrid">
                    {Array.from({ length: max_number }, (_, i) => i + 1).map((n) => (
                        <button
                            key={n}
                            type="button"
                            disabled={taken.includes(n)}
                            className={data.shirt_number === n ? 'on' : ''}
                            onClick={() => setData('shirt_number', n)}
                        >
                            {n}
                        </button>
                    ))}
                </div>
            ),
        },
        {
            q: t('alta.slots_q'),
            hint: t('alta.slots_hint'),
            ok: data.availability.length > 0,
            error: errors.availability,
            body: slots.map((s) => (
                <button key={s} type="button" className={`nc-opt ${data.availability.includes(s) ? 'on' : ''}`} onClick={() => toggleSlot(s)}>
                    {t(`slot.${s}`)}
                    {data.availability.includes(s) && <Check size={16} />}
                </button>
            )),
        },
    ];

    // El técnico solo carga el nombre; el jugador hace el wizard completo.
    const steps = role === 'coach' ? allSteps.slice(0, 1) : allSteps;

    const cur = steps[step];
    const last = step === steps.length - 1;

    const advance = () => {
        if (last) {
            post(route('alta.guardar'));
        } else {
            setStep(step + 1);
        }
    };

    return (
        <div className="nc-root nc-stage">
            <Head title={t('alta.name_q')} />
            <div className="nc-app">
                <div className="nc-step">
                    {step === 0 && (
                        <div style={{ textAlign: 'center', marginBottom: 22 }}>
                            <div style={{ display: 'flex', justifyContent: 'center' }}><Crest size={68} /></div>
                            <h1 className="nc-display" style={{ fontSize: 27, margin: '12px 0 0' }}>A.S New Castle</h1>
                            <div className="nc-label" style={{ marginTop: 4 }}>Voluntari · Ilfov · Liga a V-a</div>
                        </div>
                    )}

                    <div className="nc-progress">
                        {steps.map((_, i) => <i key={i} className={i <= step ? 'on' : ''} />)}
                    </div>

                    <div className="nc-label">{t('alta.step', { n: step + 1, total: steps.length })}</div>
                    <h2 className="nc-display nc-q">{cur.q}</h2>
                    {cur.hint && <p className="nc-meta" style={{ marginTop: -12, marginBottom: 20 }}>{cur.hint}</p>}

                    <div>{cur.body}</div>

                    {cur.error && <div className="nc-error">{cur.error}</div>}

                    {data.shirt_number !== null && step === 3 && (
                        <div style={{ display: 'flex', justifyContent: 'center', gap: 18, marginTop: 26 }}>
                            <div style={{ textAlign: 'center' }}>
                                <Kit n={data.shirt_number} kit="home" size="lg" />
                                <div className="nc-label" style={{ marginTop: 8 }}>{t('kit.home')}</div>
                            </div>
                            <div style={{ textAlign: 'center' }}>
                                <Kit n={data.shirt_number} kit="away" size="lg" />
                                <div className="nc-label" style={{ marginTop: 8 }}>{t('kit.away')}</div>
                            </div>
                        </div>
                    )}

                    <div style={{ flex: 1, minHeight: 24 }} />

                    <div style={{ display: 'flex', gap: 8 }}>
                        {step > 0 && (
                            <button type="button" className="nc-btn ghost" style={{ width: 54 }} onClick={() => setStep(step - 1)} aria-label="Back">
                                <ChevronLeft size={18} />
                            </button>
                        )}
                        <button type="button" className="nc-btn" disabled={!cur.ok || processing} onClick={advance}>
                            {last ? t('alta.finish') : t('alta.next')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
