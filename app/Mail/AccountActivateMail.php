<?php

namespace App\Mail;

use App\Mail\Traits\HasTemplate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AccountActivateMail extends Mailable
{
    use HasTemplate;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    /**
     * @return array<string, string>
     */
    public function getTemplateRecipientVars(): array
    {
        return [
            'user_name' => $this->user->getName(),
            'user_first_name' => $this->user->first_name ?? '',
            'user_last_name' => $this->user->last_name ?? '',
            'user_email' => $this->user->getEmail(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $activationToken = $this->user->getActivationToken() ?? 'preview-token';

        $activationUrl = route('filament.app.activate.account', [
            'activationToken' => $activationToken,
        ], absolute: true);

        return [
            'activate_button' => view('emails.partials.button', [
                'url' => $activationUrl,
                'label' => 'Activeren',
                'backgroundColor' => '#032d5c',
                'textColor' => '#fff',
                'fontWeight' => '600',
                'padding' => '12px 24px',
                'borderRadius' => '4px',
            ])->render(),
        ];
    }

    public function allowOverrideTo(): bool
    {
        return false;
    }

    /**
     * @throws Throwable
     */
    public function build(): self
    {
        return $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject())
            ->from(config('mail.from.address'), config('mail.from.name'));
    }

    public static function preview(): self
    {
        $user = User::query()->first();

        if ($user instanceof User) {
            if (! $user->getActivationToken()) {
                $user = clone $user;
                $user->setActivationToken('preview-token');
            }

            return new self($user);
        }

        return new self(new User([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@example.com',
            'activation_token' => 'preview-token',
        ]));
    }
}
