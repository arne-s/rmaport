<?php
namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class CriticalErrorNotification extends Notification
{
    public string $errorMessage;
    public array $debugInfo;

    public function __construct(string $errorMessage, array $debugInfo = [])
    {
        $this->errorMessage = $errorMessage;
        $this->debugInfo = $debugInfo;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $mailMessage = (new MailMessage)
            ->subject('Melding')
            ->line('Een fout is opgetreden:')
            ->line($this->errorMessage);

        if (!empty($this->debugInfo)) {
            $debugOutput = collect($this->debugInfo)
                ->map(fn($value, $key) => "**{$key}**: " . json_encode($value, JSON_PRETTY_PRINT))
                ->implode("\n");

            $mailMessage->line("\n**Debug informatie:**\n" . $debugOutput);
        }


        return $mailMessage;
    }
}
