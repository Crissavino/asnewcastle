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
        const { Browser } = await import('@capacitor/browser');
        await Browser.open({ url });
    } else {
        window.location.href = url;
    }
}
