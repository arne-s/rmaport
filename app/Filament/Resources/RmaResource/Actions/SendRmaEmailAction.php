<?php

namespace App\Filament\Resources\RmaResource\Actions;

use App\Actions\SendRmaCustomerMessageMailAction;
use App\Filament\Forms\Components\EmailRecipientSelect;
use App\Filament\Resources\RmaResource\Support\RmaCustomerMailRecipients;
use App\Filament\Support\EmailRecipientResolver;
use App\Models\MailSenderProfile;
use App\Models\Rma;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;

class SendRmaEmailAction extends Action
{
    public const SENDER_PROFILE_UID = 'orders';

    public static function getDefaultName(): ?string
    {
        return 'send_rma_customer_email';
    }

    public function getLabel(): string
    {
        return 'Mailen';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-envelope')
            ->label('Mailen')
            ->modalHeading('Klant mailen')
            ->closeModalByEscaping(false)
            ->closeModalByClickingAway(false)
            ->schema([
                Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                Group::make()
                    ->extraAttributes(['class' => 'custom-form-design', 'style' => 'margin-top: -20px'])
                    ->schema([
                        TextInput::make('from')
                            ->label('Vanaf')
                            ->required()
                            ->disabled()
                            ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel(self::SENDER_PROFILE_UID)),

                        EmailRecipientSelect::make('to')
                            ->label('To')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->default(fn ($livewire) => self::getDefaultToRecipients($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('cc')
                            ->label('CC')
                            ->options(fn ($livewire) => EmailRecipientResolver::getRecipientOptions())
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('bcc')
                            ->label('BCC')
                            ->options(fn ($livewire) => EmailRecipientResolver::getRecipientOptions())
                            ->columnSpanFull(),

                        TextInput::make('subject')
                            ->label('Onderwerp')
                            ->required(),
                    ]),

                Section::make('Bericht')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        RichEditor::make('message')
                            ->hiddenLabel()
                            ->label('Bericht')
                            ->extraAttributes(['class' => 'rich-editor-min-height'])
                            ->required()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'link',
                                'alignStart', 'alignCenter', 'alignEnd',
                                'bulletList',
                                'orderedList',
                                'table',
                                'redo',
                                'undo',
                            ])
                            ->disableToolbarButtons(['attachFiles'])
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data, $livewire): void {
                $rma = $livewire->record;
                if (! $rma instanceof Rma) {
                    return;
                }

                $toEmails = RmaCustomerMailRecipients::resolveEmails($rma, $data['to'] ?? []);
                if ($toEmails === []) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();

                    return;
                }

                $ccEmails = EmailRecipientResolver::resolveRecipients($data['cc'] ?? []);
                $bccEmails = EmailRecipientResolver::resolveRecipients($data['bcc'] ?? []);

                app(SendRmaCustomerMessageMailAction::class)->execute(
                    rma: $rma,
                    toAddress: $toEmails,
                    subject: (string) $data['subject'],
                    body: (string) $data['message'],
                    cc: array_map(fn (string $email): array => ['name' => null, 'email' => $email], $ccEmails),
                    bcc: array_map(fn (string $email): array => ['name' => null, 'email' => $email], $bccEmails),
                    microsoftMailTokenId: MailSenderProfile::tokenIdByUid(self::SENDER_PROFILE_UID),
                );

                Notification::make()
                    ->title('E-mail verzonden')
                    ->body('E-mail is verzonden naar: '.implode(', ', $toEmails))
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Versturen')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipients($livewire): array
    {
        $record = $livewire->record;

        return $record instanceof Rma
            ? RmaCustomerMailRecipients::defaultToRecipients($record)
            : [];
    }

    /**
     * @return array<string, string>
     */
    private static function getRecipientOptions($livewire): array
    {
        $record = $livewire->record;

        if (! $record instanceof Rma) {
            return EmailRecipientResolver::getRecipientOptions();
        }

        return array_merge(
            EmailRecipientResolver::getRecipientOptions(),
            RmaCustomerMailRecipients::recipientOptions($record),
        );
    }
}
