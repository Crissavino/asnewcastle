import { Capacitor } from '@capacitor/core';

export const isNative = () => Capacitor.isNativePlatform();

/**
 * Abre el checkout de Stripe. En la app nativa lo abre en el navegador del
 * sistema (SFSafariViewController / Custom Tabs), donde Apple Pay y Google Pay
 * SÍ funcionan; al terminar, la página puente reabre la app por deep link.
 * En la web es una redirección normal.
 */
export async function openCheckout(url) {
    if (Capacitor.isNativePlatform()) {
        try {
            const { Browser } = await import('@capacitor/browser');
            await Browser.open({ url });
            return;
        } catch (e) {
            // Si el plugin Browser no está en el build nativo, abrimos igual:
            // el checkout va al navegador y la vuelta usa el esquema asnewcastle://
        }
    }
    window.location.href = url;
}

/**
 * Abre una URL externa (p. ej. la descarga del APK nuevo) en el navegador del
 * sistema. Resuelve rutas relativas contra el origen actual para que funcione
 * dentro del webview de Capacitor.
 */
export async function openExternal(url) {
    const abs = /^https?:\/\//i.test(url) ? url : window.location.origin + url;
    if (Capacitor.isNativePlatform()) {
        try {
            const { Browser } = await import('@capacitor/browser');
            await Browser.open({ url: abs });
            return;
        } catch (e) {
            // sin el plugin Browser, caemos a la navegación normal
        }
    }
    window.location.href = abs;
}
