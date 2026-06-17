<?php

use App\Enums\EmailTemplateAudience;
use App\Enums\EmailTemplateType;
use App\Mail\ExportRmaMail;
use App\Models\EmailTemplate;
use App\Models\MailSenderProfile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $content = <<<'HTML'
<h2 style="text-align: justify;">Beste [customer.name],</h2><p>
Bij deze ontvangt u het ingediende overzicht RMA-verzoeken retour. Dit bestand is ingediend onder referentie [import.reference].</p>

<p>U kunt de producten binnen 14 dagen terugsturen onder vermelding van het RMA-nummer.</p>

<p>Indien u vragen heeft kunt u contact opnemen met <a href="mailto:info@autovision.nl">info@autovision.nl</a>.</p>
HTML;

        $profileId = MailSenderProfile::query()
            ->where('uid', 'orders')
            ->value('id');

        EmailTemplate::query()->updateOrCreate(
            ['class' => ExportRmaMail::class],
            [
                'subject' => 'RMA-verzoeken voor #[import.reference]',
                'content' => $content,
                'name' => 'Bulk RMA retour',
                'description' => 'Bulk RMA retour',
                'type' => EmailTemplateType::General,
                'audience' => EmailTemplateAudience::External,
                'mail_sender_profile_id' => $profileId,
            ],
        );
    }

    public function down(): void
    {
        EmailTemplate::query()
            ->where('class', ExportRmaMail::class)
            ->delete();
    }
};
