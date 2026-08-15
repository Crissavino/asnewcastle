import AppLayout from '../Layouts/AppLayout';
import { useTranslations } from '../i18n';

export default function Agenda() {
    const { t } = useTranslations();

    return (
        <AppLayout tab="agenda">
            <div className="nc-card">
                <p className="nc-meta">{t('empty.agenda')}</p>
            </div>
        </AppLayout>
    );
}
