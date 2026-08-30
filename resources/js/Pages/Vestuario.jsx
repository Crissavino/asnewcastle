import { router, useForm, usePage } from '@inertiajs/react';
import { Camera, Send, Star, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB', ar: 'ar-u-nu-latn' };
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
    // Vota y califica solo el que estuvo en el partido (es candidato)
    const canVote = mvp.candidates.some((c) => c.id === member?.id);

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
                                    disabled={!canVote || c.id === member?.id}
                                    aria-label={t('vestuario.mvp_title')}
                                >
                                    <Star size={16} fill={mvp.my_vote === c.id ? 'currentColor' : 'none'} />
                                    <b className="nc-num">{c.votes}</b>
                                </button>
                            </div>
                            <div className="nc-poll-bar">
                                <i style={{ width: `${(c.votes / maxVotes) * 100}%` }} />
                            </div>
                            {canVote && c.id !== member?.id && (
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

export default function Vestuario({ messages, mvp, roster_count, first_unread_id }) {
    const { t, locale } = useTranslations();
    const intl = INTL_LOCALES[locale] ?? 'en-GB';
    const end = useRef(null);
    const scrollBox = useRef(null);
    const taRef = useRef(null);
    // El textarea del composer crece con el texto (hasta ~5 líneas, después scrollea).
    const autoGrow = () => {
        const el = taRef.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 120) + 'px';
    };
    // Se captura en el primer render: al entrar arrancamos ahí (los polls no lo pisan).
    const firstUnread = useRef(first_unread_id);
    const didInit = useRef(false);
    const fileRef = useRef(null);
    const [preview, setPreview] = useState(null);
    const [lightbox, setLightbox] = useState(null);
    const [pollOpen, setPollOpen] = useState(false);
    // Traducciones de mensajes de compañeros: { [id]: { status, text, showing } }
    const [tr, setTr] = useState({});
    const { data, setData, post, processing, reset } = useForm({ body: '', image: null });

    // Traduce un mensaje al tocar el link (nunca antes). El backend cachea:
    // el segundo que traduce el mismo mensaje no genera otra llamada a la API.
    const translate = (m) => {
        const cur = tr[m.id];
        if (cur?.status === 'loading') return;
        if (cur?.status === 'done') {
            // toggle traducción <-> original
            setTr((s) => ({ ...s, [m.id]: { ...cur, showing: cur.showing === 'translation' ? 'original' : 'translation' } }));
            return;
        }
        setTr((s) => ({ ...s, [m.id]: { status: 'loading' } }));
        window.axios.post(route('vestuario.traducir', m.id))
            .then((r) => setTr((s) => ({
                ...s,
                [m.id]: r.data?.ok
                    ? { status: 'done', text: r.data.text, showing: 'translation' }
                    : { status: 'failed' },
            })))
            .catch(() => setTr((s) => ({ ...s, [m.id]: { status: 'failed' } })));
    };

    // Polling cada 8 segundos: refresca mensajes y votación, sin tocar el input
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['messages', 'mvp'] });
        }, POLL_MS);
        return () => clearInterval(id);
    }, []);

    // ¿Está pegado al fondo? Se actualiza con cada scroll del usuario, así el
    // poll sabe si seguir bajando o dejarlo donde está leyendo.
    const pinned = useRef(true);
    const scrollBottom = () => {
        const b = scrollBox.current;
        if (b) b.scrollTop = b.scrollHeight;
    };

    useEffect(() => {
        const b = scrollBox.current;
        if (!b) return;
        const onScroll = () => {
            pinned.current = b.scrollHeight - b.scrollTop - b.clientHeight < 60;
        };
        b.addEventListener('scroll', onScroll, { passive: true });
        return () => b.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => {
        // Primer ingreso: arrancar en el primer mensaje sin leer; si leíste
        // todo, abajo. Se reajusta cuando cargan las imágenes (si no, el layout
        // se corre y quedás en una posición vieja).
        if (!didInit.current) {
            didInit.current = true;
            const target = firstUnread.current;
            pinned.current = ! target; // si arranca mid, no está pegado al fondo
            const go = () => {
                const el = target && document.getElementById(`msg-${target}`);
                if (el) el.scrollIntoView({ block: 'start' });
                else scrollBottom();
            };
            go();
            const imgs = [...(scrollBox.current?.querySelectorAll('img') ?? [])].filter((i) => !i.complete);
            imgs.forEach((i) => i.addEventListener('load', go, { once: true }));
            const tid = setTimeout(go, 400);
            return () => { clearTimeout(tid); imgs.forEach((i) => i.removeEventListener('load', go)); };
        }

        // Poll: seguir el chat hacia abajo solo si ya estabas pegado al fondo.
        if (pinned.current) {
            scrollBottom();
        }
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
                if (taRef.current) taRef.current.style.height = ''; // vuelve a una línea (min-height)
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
        if (params.period) {
            params.period = new Date(params.period).toLocaleDateString(intl, { month: 'long' });
        }
        return t(system.key, params);
    };

    return (
        <AppLayout tab="vestuario" eyebrow={t('vestuario.players', { count: roster_count })}>
            <div className="nc-chat">
                <div className="nc-chat-scroll" ref={scrollBox}>
                    {messages.length === 0 && (
                        <p className="nc-meta" style={{ textAlign: 'center', padding: '40px 30px' }}>
                            {t('empty.vestuario')}
                        </p>
                    )}
                    {(() => {
                        // Mensajes agrupados por autor + separadores de día, estilo chat
                        const items = [];
                        let prevKey = null;
                        let prevDay = null;

                        messages.forEach((m) => {
                            const day = new Date(m.at).toDateString();
                            if (day !== prevDay) {
                                items.push(
                                    <div key={`d${m.id}`} className="nc-daysep">
                                        <span>{new Date(m.at).toLocaleDateString(intl, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
                                    </div>,
                                );
                                prevDay = day;
                                prevKey = null;
                            }

                            if (m.system) {
                                items.push(<div key={m.id} className="nc-sys">{systemText(m.system)}</div>);
                                prevKey = null;
                                return;
                            }

                            const key = (m.mine ? 'me' : m.author?.name ?? '?');
                            const first = key !== prevKey;
                            prevKey = key;

                            items.push(
                                <div key={m.id} id={`msg-${m.id}`} className={`nc-msg ${m.mine ? 'mine' : ''} ${first ? 'first' : 'chain'}`}>
                                    {!m.mine && (first
                                        ? <Kit n={m.author?.shirt_number} size="sm" />
                                        : <div className="nc-kit-spacer" />
                                    )}
                                    <div className="nc-msg-col">
                                        {first && !m.mine && <div className="nc-author">{m.author?.name}</div>}
                                        {(() => {
                                            const st = tr[m.id];
                                            const showTr = st?.status === 'done' && st.showing === 'translation';
                                            // Link solo en mensajes de otros, con texto y en otro idioma
                                            const canTranslate = !m.mine && !!m.body && m.detected_locale !== locale;
                                            return (
                                                <>
                                                    <div className="nc-bubble">
                                                        {m.attachment && (
                                                            <img
                                                                className="nc-photo"
                                                                src={m.attachment}
                                                                alt=""
                                                                onClick={() => setLightbox(m.attachment)}
                                                            />
                                                        )}
                                                        {showTr ? (
                                                            <>
                                                                <span className="nc-orig">{m.body}</span>
                                                                <span className="nc-tr-text">{st.text}</span>
                                                            </>
                                                        ) : m.body}
                                                        <span className="nc-time">{timeOf(m.at)}</span>
                                                    </div>
                                                    {canTranslate && (
                                                        <button
                                                            type="button"
                                                            className="nc-tr-link"
                                                            onClick={() => translate(m)}
                                                            disabled={st?.status === 'loading'}
                                                        >
                                                            {st?.status === 'loading' ? t('vestuario.translating')
                                                                : st?.status === 'failed' ? t('vestuario.translate_failed')
                                                                    : showTr ? t('vestuario.show_original')
                                                                        : t('vestuario.translate')}
                                                        </button>
                                                    )}
                                                </>
                                            );
                                        })()}
                                    </div>
                                </div>,
                            );
                        });

                        return items;
                    })()}
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
                    <textarea
                        ref={taRef}
                        rows={1}
                        value={data.body}
                        onChange={(e) => { setData('body', e.target.value); autoGrow(); }}
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
