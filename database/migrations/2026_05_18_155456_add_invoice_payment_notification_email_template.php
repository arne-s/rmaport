<?php

use App\Enums\EmailTemplateAudience;
use App\Enums\EmailTemplateType;
use App\Mail\Unit\InvoicePaymentNotificationMail;
use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $content = <<<'HTML'
<h2 style="text-align: justify;">Beste Isabel, administratie,</h2><p style="text-align: justify;">Er is een betaling geregistreerd voor de factuur voor aanvraag: <a target="_blank" rel="noopener noreferrer nofollow" href="[order_link]">[main_number]</a></p><p style="text-align: justify;">Succes!</p>
HTML;

        EmailTemplate::query()->firstOrCreate(
            ['class' => InvoicePaymentNotificationMail::class],
            [
                'subject' => 'Factuur | Betaling geregistreerd bij onderdeel #[main_number] | RD Mobility',
                'content' => $content,
                'name' => 'Bevestiging van betaling onderdeel',
                'description' => 'Factuur | Bevestiging betaling (intern)',
                'type' => EmailTemplateType::Part,
                'audience' => EmailTemplateAudience::Internal,
                'mail_sender_profile_id' => null,
            ],
        );
    }

    public function down(): void
    {
        EmailTemplate::query()
            ->where('class', InvoicePaymentNotificationMail::class)
            ->delete();
    }
};
