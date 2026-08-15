import { router, useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useEffect, useRef } from 'react';
import AppLayout from '../Layouts/AppLayout';
import Kit from '../Components/Kit';
import { useTranslations } from '../i18n';

const INTL_LOCALES = { es: 'es-AR', ro: 'ro-RO', en: 'en-GB' };
const POLL_MS = 8000;

export default function Vestuario({ messages, roster_count }) {
    const { t, locale } = useTranslations();
    const intl = INTL_LOCALES[locale] ?? 'en-GB';
    const end = useRef(null);
    const { data, setData, post, processing, reset } = useForm({ body: '' });

    // Polling cada 8 segundos: solo recarga los mensajes, sin tocar el input
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['messages'] });
        }, POLL_MS);
        return () => clearInterval(id);
    }, []);

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [messages]);

    const send = (e) => {
        e.preventDefault();
        if (!data.body.trim()) return;
        post(route('vestuario.enviar'), {
            preserveScroll: true,
            onSuccess: () => reset(),
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
                                    <div className="nc-bubble">{m.body}</div>
                                    <div className="nc-meta" style={{ fontSize: 11, marginTop: 3, textAlign: m.mine ? 'right' : 'left' }}>
                                        {m.mine ? t('vestuario.you') : m.author?.name} · {timeOf(m.at)}
                                    </div>
                                </div>
                            </div>
                        )
                    )}
                    <div ref={end} />
                </div>

                <form className="nc-composer" onSubmit={send}>
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
        </AppLayout>
    );
}
