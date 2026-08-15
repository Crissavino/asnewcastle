import { usePage } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { useTranslations } from '../i18n';

export default function Tabla() {
    const { t } = useTranslations();
    const { club } = usePage().props;

    return (
        <AppLayout tab="tabla" eyebrow={club?.league ?? t('headers.tabla_eyebrow')}>
            <div className="nc-card">
                <p className="nc-meta">{t('empty.tabla')}</p>
            </div>
        </AppLayout>
    );
}
