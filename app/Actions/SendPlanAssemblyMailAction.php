<?php

namespace App\Actions;

use App\Mail\Unit\PlanAssemblyMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPlanAssemblyMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order): void
    {
        $template = EmailTemplate::query()->where('class', PlanAssemblyMail::class)->first();
        if ($template === null) {
            Log::warning('PlanAssemblyMail: e-mailtemplate niet gevonden (class ' . PlanAssemblyMail::class . ').');

            return;
        }

        $to = $template->getUsersTo()
            ->map(fn ($user): array => [
                'name' => $user->getName(),
                'email' => $user->getEmail(),
            ])
            ->filter(fn (array $recipient): bool => is_string($recipient['email'] ?? null) && $recipient['email'] !== '')
            ->values()
            ->all();

        try {
            Mail::send(new PlanAssemblyMail($order));
            $this->logger->logSent($order, PlanAssemblyMail::class, $to);
        } catch (\Throwable $e) {
            Log::error('PlanAssemblyMail: verzenden mislukt: ' . $e->getMessage());
        }
    }
}
