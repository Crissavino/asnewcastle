import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Capacitor } from '@capacitor/core';

createInertiaApp({
    title: (title) => (title ? `${title} — A.S New Castle` : 'A.S New Castle'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#D22233',
    },
});

// Altura REAL del viewport: Safari iOS mueve la barra de abajo y el teclado,
// y 100vh/100dvh no siempre la siguen. visualViewport no miente nunca.
const setAppVh = () => {
    const h = window.visualViewport?.height ?? window.innerHeight;
    document.documentElement.style.setProperty('--app-vh', `${h}px`);
};
setAppVh();
window.visualViewport?.addEventListener('resize', setAppVh);
window.addEventListener('resize', setAppVh);
window.addEventListener('orientationchange', setAppVh);

// PWA: el service worker cachea solo estáticos inmutables (ver public/sw.js)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // sin SW no pasa nada: la app funciona igual, solo no cachea estáticos
        });
    });
}

// Push nativas: solo dentro del shell de Capacitor (en el navegador es no-op).
// Pide permiso una vez, registra el token en el servidor y abre la pantalla
// correcta al tocar la notificación.
async function setupNativePush() {
    if (!Capacitor.isNativePlatform()) {
        return;
    }

    const { PushNotifications } = await import('@capacitor/push-notifications');

    let perm = await PushNotifications.checkPermissions();
    if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') {
        perm = await PushNotifications.requestPermissions();
    }
    if (perm.receive !== 'granted') {
        return;
    }

    PushNotifications.addListener('registration', (token) => {
        window.axios
            .post('/push/token', { token: token.value, platform: Capacitor.getPlatform() })
            .catch(() => {});
    });

    PushNotifications.addListener('pushNotificationActionPerformed', (action) => {
        const url = action.notification?.data?.url;
        if (url) {
            window.location.href = url;
        }
    });

    await PushNotifications.register();
}

setupNativePush();
