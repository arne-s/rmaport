<?php

namespace App\Actions;

use App\Mail\Unit\AssemblyStartMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAssemblyStartMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order): void
    {
        $template = EmailTemplate::query()->where('class', AssemblyStartMail::class)->first();
        if ($template === null) {
            Log::warning('AssemblyStartMail: e-mailtemplate niet gevonden (class ' . AssemblyStartMail::class . ').');

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
            Mail::send(new AssemblyStartMail($order));
            $this->logger->logSent($order, AssemblyStartMail::class, $to);
        } catch (\Throwable $e) {
            Log::error('AssemblyStartMail: verzenden mislukt: ' . $e->getMessage());
        }
    }
}
