import { router, useForm, usePage } from '@inertiajs/react';
import { Camera, Send, Star, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB' };
const POLL_MS = 8000;

function MvpPoll({ mvp, onClose }) {
    const { t } = useTranslations();
    const { member } = usePage().props;

    const voteFigura = (memberId) => {
        router.post(route('figura.votar', mvp.event_id), { member_id: memberId }, { preserveScroll: true });
    };

    const rate = (memberId, rating) => {
        router.post(route('puntaje.votar', mvp.event_id), { member_id: memberId, rating }, { preserveScroll: true });
    };

    const maxVotes = Math.max(...mvp.candidates.map((c) => c.votes), 1);

    return (
        <div className="nc-sheet" onClick={onClose}>
            <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
                <div className="nc-label">{t('vestuario.mvp_title')}</div>
                <p className="nc-meta" style={{ marginTop: 6 }}>{t('vestuario.mvp_hint', { opponent: mvp.opponent })}</p>

                <div style={{ marginTop: 10 }}>
                    {mvp.candidates.map((c) => (
                        <div key={c.id} className="nc-poll-row">
                            <div className="nc-poll-head">
                                <Kit n={c.shirt_number} size="sm" />
                                <span className="nc-poll-name">{c.name}</span>
                                <button
                                    type="button"
                                    className={`nc-star ${mvp.my_vote === c.id ? 'on' : ''}`}
                                    onClick={() => voteFigura(c.id)}
                                    aria-label={t('vestuario.mvp_title')}
                                >
                                    <Star size={16} fill={mvp.my_vote === c.id ? 'currentColor' : 'none'} />
                                    <b className="nc-num">{c.votes}</b>
                                </button>
                            </div>
                            <div className="nc-poll-bar">
                                <i style={{ width: `${(c.votes / maxVotes) * 100}%` }} />
                            </div>
                            {c.id !== member?.id && (
                                <div className="nc-rate">
                                    {[1, 2, 3].map((r) => (
                                        <button
                                            key={r}
                                            type="button"
                                            className={c.my_rating === r ? 'on' : ''}
                                            onClick={() => rate(c.id, r)}
                                        >
                                            {t(`rate.${r}`)}
                                            {c.ratings[r - 1] > 0 && <b className="nc-num">{c.ratings[r - 1]}</b>}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

export default function Vestuario({ messages, mvp, roster_count }) {
    const { t, locale } = useTranslations();
    const intl = INTL_LOCALES[locale] ?? 'en-GB';
    const end = useRef(null);
    const fileRef = useRef(null);
    const [preview, setPreview] = useState(null);
    const [lightbox, setLightbox] = useState(null);
    const [pollOpen, setPollOpen] = useState(false);
    const { data, setData, post, processing, reset } = useForm({ body: '', image: null });

    // Polling cada 8 segundos: refresca mensajes y votación, sin tocar el input
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['messages', 'mvp'] });
        }, POLL_MS);
        return () => clearInterval(id);
    }, []);

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [messages]);

    // Las fotos del teléfono pesan 4-8MB: se achican a 1600px antes de subir
    const compress = (file) => new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            const max = 1600;
            let { width: w, height: h } = img;
            if (Math.max(w, h) > max) {
                const r = max / Math.max(w, h);
                w = Math.round(w * r);
                h = Math.round(h * r);
            }
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            canvas.getContext('2d').drawImage(img, 0, 0, w, h);
            canvas.toBlob(
                (blob) => resolve(blob ? new File([blob], 'foto.jpg', { type: 'image/jpeg' }) : file),
                'image/jpeg',
                0.82,
            );
        };
        img.onerror = () => resolve(file);
        img.src = URL.createObjectURL(file);
    });

    const pickImage = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const small = await compress(file);
        setData('image', small);
        setPreview(URL.createObjectURL(small));
    };

    const clearImage = () => {
        setData('image', null);
        setPreview(null);
        if (fileRef.current) fileRef.current.value = '';
    };

    const send = (e) => {
        e.preventDefault();
        if (!data.body.trim() && !data.image) return;
        post(route('vestuario.enviar'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                clearImage();
            },
        });
    };

    const timeOf = (iso) => new Date(iso).toLocaleTimeString(intl, { hour: '2-digit', minute: '2-digit', hour12: false });

    const systemText = (system) => {
        const params = { ...(system.params ?? {}) };
        if (params.date) {
            params.date = new Date(params.date).toLocaleDateString(intl, {
                weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
            });
        }
        return t(system.key, params);
    };

    return (
        <AppLayout tab="vestuario" eyebrow={t('vestuario.players', { count: roster_count })}>
            <div style={{ display: 'flex', flexDirection: 'column', minHeight: '100%' }}>
                <div style={{ flex: 1 }}>
                    {messages.map((m) =>
                        m.system ? (
                            <div key={m.id} className="nc-sys">{systemText(m.system)}</div>
                        ) : (
                            <div key={m.id} className={`nc-msg ${m.mine ? 'mine' : ''}`}>
                                <Kit n={m.author?.shirt_number} size="sm" />
                                <div>
                                    <div className="nc-bubble">
                                        {m.attachment && (
                                            <img
                                                className="nc-photo"
                                                src={m.attachment}
                                                alt=""
                                                onClick={() => setLightbox(m.attachment)}
                                            />
                                        )}
                                        {m.body}
                                    </div>
                                    <div className="nc-meta" style={{ fontSize: 11, marginTop: 3, textAlign: m.mine ? 'right' : 'left' }}>
                                        {m.mine ? t('vestuario.you') : m.author?.name} · {timeOf(m.at)}
                                    </div>
                                </div>
                            </div>
                        )
                    )}
                    <div ref={end} />
                </div>

                {/* La encuesta vive detrás de un botón flotante para no comerse el chat */}
                {mvp && (
                    <button type="button" className="nc-fab" onClick={() => setPollOpen(true)} aria-label={t('vestuario.mvp_title')}>
                        <Star size={22} fill={mvp.my_vote ? 'currentColor' : 'none'} />
                        {mvp.total_votes > 0 && <b className="nc-num">{mvp.total_votes}</b>}
                    </button>
                )}

                {preview && (
                    <div className="nc-preview">
                        <img src={preview} alt="" />
                        <span className="nc-meta">{t('vestuario.photo_ready')}</span>
                        <button type="button" onClick={clearImage} aria-label="X"><X size={15} /></button>
                    </div>
                )}

                <form className="nc-composer" onSubmit={send}>
                    <input type="file" accept="image/*" hidden ref={fileRef} onChange={pickImage} />
                    <button type="button" className="nc-icon-btn ghost" onClick={() => fileRef.current?.click()} aria-label={t('vestuario.photo')}>
                        <Camera size={17} />
                    </button>
                    <input
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        placeholder={t('vestuario.placeholder')}
                        maxLength={500}
                    />
                    <button type="submit" className="nc-icon-btn" disabled={processing} aria-label={t('vestuario.placeholder')}>
                        <Send size={17} />
                    </button>
                </form>
            </div>

            {pollOpen && mvp && <MvpPoll mvp={mvp} onClose={() => setPollOpen(false)} />}

            {lightbox && (
                <div className="nc-lightbox" onClick={() => setLightbox(null)}>
                    <img src={lightbox} alt="" />
                </div>
            )}
        </AppLayout>
    );
}
