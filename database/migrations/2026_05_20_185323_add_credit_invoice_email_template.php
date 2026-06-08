<?php

use App\Enums\EmailTemplateAudience;
use App\Enums\EmailTemplateType;
use App\Mail\CreditInvoiceMail;
use App\Models\EmailTemplate;
use App\Models\MailSenderProfile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $content = <<<'HTML'
<p style="text-align: center;">Beste [customer_first_name],</p><p style="text-align: center;"><br>Hierbij ontvangt u de creditfactuur [invoice_number].<br/><br/>[invoice_download_button]</p><p style="text-align: center;">Met vriendelijke groeten,<br>RD Mobility</p>
HTML;

        $profileId = MailSenderProfile::query()
            ->where('uid', 'invoices')
            ->value('id');

        EmailTemplate::query()->firstOrCreate(
            ['class' => CreditInvoiceMail::class],
            [
                'subject' => 'Creditfactuur: [invoice_number] | RD Mobility',
                'content' => $content,
                'name' => 'Factuur | Creditfactuur',
                'description' => 'Factuur | Creditfactuur',
                'type' => EmailTemplateType::General,
                'audience' => EmailTemplateAudience::External,
                'mail_sender_profile_id' => $profileId,
            ],
        );
    }

    public function down(): void
    {
        EmailTemplate::query()
            ->where('class', CreditInvoiceMail::class)
            ->delete();
    }
};
