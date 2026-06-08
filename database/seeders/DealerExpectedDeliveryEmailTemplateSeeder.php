<?php

namespace Database\Seeders;

use App\Enums\EmailTemplateAudience;
use App\Enums\EmailTemplateType;
use App\Mail\DealerExpectedDeliveryMail;
use App\Mail\DealerNewExpectedDeliveryMail;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class DealerExpectedDeliveryEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $expectedDeliveryContent = <<<'HTML'
<h2 style="text-align: center;"><strong>Order #[order_number]</strong></h2>
<p style="text-align: center;">Beste [customer_name],</p>
<p style="text-align: center;">Het verwachte <u>levermoment</u> van ordernummer #[order_number], voor klant [order_customer_name], is: <strong>[delivery_date]</strong></p>
<p style="text-align: center;"><u>Betreffende producten:</u></p>
<p style="text-align: center;">[products]<br></p>
<p style="text-align: center;">Bedankt voor de bestelling en wij gaan er vanuit je hiermee voldoende geïnformeerd te hebben.</p>
<p style="text-align: center;">Met vriendelijke groeten,<br>RD Mobility</p>
HTML;

        EmailTemplate::query()->firstOrCreate(
            ['class' => DealerExpectedDeliveryMail::class],
            [
                'subject' => 'Order #[order_number] | Verwacht levermoment',
                'content' => $expectedDeliveryContent,
                'name' => 'Leverdatum naar dealer',
                'description' => 'Productie | Verwacht levermoment',
                'type' => EmailTemplateType::General,
                'audience' => EmailTemplateAudience::External,
                'mail_sender_profile_id' => null,
            ],
        );

        $newExpectedDeliveryContent = <<<'HTML'
<h2 style="text-align: center;"><strong>Order #[order_number]</strong></h2>
<p style="text-align: center;">Beste [customer_name],</p>
<p style="text-align: center;">Het <u>gewijzigde</u> verwachte levermoment van ordernummer #[order_number], voor klant [order_customer_name], is: <strong>[delivery_date]</strong></p>
<p style="text-align: center;"><u>Betreffende producten:</u></p>
<p style="text-align: center;">[products]<br></p>
<p style="text-align: center;">Bedankt voor de bestelling en wij gaan er vanuit je hiermee voldoende geïnformeerd te hebben.</p>
<p style="text-align: center;">Met vriendelijke groeten,<br>RD Mobility</p>
HTML;

        EmailTemplate::query()->firstOrCreate(
            ['class' => DealerNewExpectedDeliveryMail::class],
            [
                'subject' => 'Order #[order_number] | Gewijzigd verwacht levermoment',
                'content' => $newExpectedDeliveryContent,
                'name' => 'Gewijzigde leverdatum naar dealer',
                'description' => 'Productie | Gewijzigd verwacht levermoment',
                'type' => EmailTemplateType::General,
                'audience' => EmailTemplateAudience::External,
                'mail_sender_profile_id' => null,
            ],
        );
    }
}
