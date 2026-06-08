<?php

namespace App\Providers;

use Monolog\LogRecord;
use App\Notifications\CriticalErrorNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\AbstractProcessingHandler;

class LogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Log::channel('critical')->getLogger()->pushHandler(new class extends AbstractProcessingHandler {
            protected function write(array|LogRecord $record): void
            {
                $adminEmails = ['arne@dunico.nl', 'tiemen@dunico.nl'];
                $debugInfo = $record['context'] ?? [];

                foreach ($adminEmails as $email) {
                    Notification::route('mail', $email)
                        ->notify(new CriticalErrorNotification($record['message'], $debugInfo));
                }
            }
        });
    }
}
