import { usePage } from '@inertiajs/react';

/**
 * Traducciones key-value compartidas por Inertia desde lang/{locale}.json.
 * Uso: const { t } = useTranslations(); t('auth.phone_title')
 * Placeholders estilo Laravel: t('auth.code_hint', { phone: '+40•••678' })
 */
export function useTranslations() {
    const { translations, locale } = usePage().props;

    const t = (key, replacements = {}) => {
        let text = translations?.[key] ?? key;

        for (const [name, value] of Object.entries(replacements)) {
            text = text.replaceAll(`:${name}`, String(value));
        }

        return text;
    };

    return { t, locale };
}

export const LOCALES = [
    { code: 'en', label: 'English' },
    { code: 'ro', label: 'Română' },
    { code: 'es', label: 'Español' },
];
