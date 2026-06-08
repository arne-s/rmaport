<?php

namespace App\Actions;

use App\Mail\Unit\AssemblyCompletedMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAssemblyCompletedMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order): void
    {
        $template = EmailTemplate::query()->where('class', AssemblyCompletedMail::class)->first();
        if ($template === null) {
            Log::warning('AssemblyCompletedMail: e-mailtemplate niet gevonden (class ' . AssemblyCompletedMail::class . ').');

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
            Mail::send(new AssemblyCompletedMail($order));
            $this->logger->logSent($order, AssemblyCompletedMail::class, $to);
        } catch (\Throwable $e) {
            Log::error('AssemblyCompletedMail: verzenden mislukt: ' . $e->getMessage());
        }
    }
}
