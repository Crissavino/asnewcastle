import AppLayout from '../Layouts/AppLayout';
import { useTranslations } from '../i18n';

export default function Vestuario() {
    const { t } = useTranslations();

    return (
        <AppLayout tab="vestuario">
            <div className="nc-card">
                <p className="nc-meta">{t('empty.vestuario')}</p>
            </div>
        </AppLayout>
    );
}
