import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
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
let fcmToken = null;

function postToken() {
    if (!fcmToken) {
        return;
    }
    window.axios
        .post('/push/token', { token: fcmToken, platform: Capacitor.getPlatform() })
        .catch(() => {});
}

async function setupNativePush() {
    if (!Capacitor.isNativePlatform()) {
        return;
    }

    // Firebase Cloud Messaging: da un token FCM tanto en Android como en iOS
    // (en iOS, Firebase hace el puente con APNs), así el servidor manda por un
    // solo canal para las dos plataformas.
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging');

    let perm = await FirebaseMessaging.checkPermissions();
    if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') {
        perm = await FirebaseMessaging.requestPermissions();
    }
    if (perm.receive !== 'granted') {
        return;
    }

    FirebaseMessaging.addListener('tokenReceived', (event) => {
        fcmToken = event.token;
        postToken();
    });

    FirebaseMessaging.addListener('notificationActionPerformed', (event) => {
        const url = event.notification?.data?.url;
        if (url) {
            window.location.href = url;
        }
    });

    // El token puede llegar antes del login (pantalla de teléfono), donde
    // /push/token todavía da 401. Reintentamos tras cada navegación de Inertia
    // (p. ej. después de entrar), así queda asociado al usuario apenas se loguea.
    router.on('navigate', () => postToken());

    try {
        const { token } = await FirebaseMessaging.getToken();
        fcmToken = token;
        postToken();
    } catch {
        // sin token no pasa nada: la app funciona igual, solo no recibe push
    }
}

setupNativePush();
