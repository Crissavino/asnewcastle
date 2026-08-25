import { usePage } from '@inertiajs/react';
import { Capacitor } from '@capacitor/core';
import { useEffect, useState } from 'react';
import { openExternal } from '../native';
import { useTranslations } from '../i18n';

/**
 * Control de versión del APK de Android. El sideload no se auto-actualiza como
 * Play/TestFlight, así que la app compara su propio versionCode con el último
 * publicado (prop compartido android_update.latest_code) y, si quedó atrás,
 * propone bajar el APK nuevo. Solo corre en la app nativa Android; en iOS y en
 * la web no hace nada. Si el jugador dice "más tarde", no vuelve a molestar en
 * esa sesión.
 */
export default function AndroidUpdate() {
    const { t } = useTranslations();
    const { android_update } = usePage().props;
    const [show, setShow] = useState(false);

    useEffect(() => {
        let cancelled = false;

        (async () => {
            if (Capacitor.getPlatform() !== 'android' || !Capacitor.isNativePlatform()) {
                return;
            }
            if (sessionStorage.getItem('apkUpdateDismissed')) {
                return;
            }
            try {
                const { App } = await import('@capacitor/app');
                const info = await App.getInfo();
                const installed = Number(info.build) || 0;
                if (!cancelled && installed < (android_update?.latest_code ?? 0)) {
                    setShow(true);
                }
            } catch {
                // sin @capacitor/app no podemos comparar: no molestamos
            }
        })();

        return () => { cancelled = true; };
    }, [android_update]);

    if (!show) {
        return null;
    }

    const update = () => openExternal(android_update.apk_url);
    const later = () => {
        sessionStorage.setItem('apkUpdateDismissed', '1');
        setShow(false);
    };

    return (
        <div className="nc-sheet" role="dialog" aria-modal="true">
            <div className="nc-sheet-inner">
                <h2 className="nc-display nc-q">{t('update.title')}</h2>
                <p className="nc-meta" style={{ marginBottom: 18 }}>{t('update.body')}</p>
                <button type="button" className="nc-btn" onClick={update}>{t('update.now')}</button>
                <button type="button" className="nc-skip" onClick={later}>{t('update.later')}</button>
            </div>
        </div>
    );
}
