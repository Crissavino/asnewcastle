import AppLayout from '../Layouts/AppLayout';
import { useTranslations } from '../i18n';

export default function Cuota() {
    const { t } = useTranslations();

    return (
        <AppLayout tab="cuota">
            <div className="nc-card">
                <p className="nc-meta">{t('empty.cuota')}</p>
            </div>
        </AppLayout>
    );
}
