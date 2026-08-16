import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

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
