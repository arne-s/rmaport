<?php

namespace App\Filament\Resources\RecurringInvoices\Actions;

use App\Filament\Resources\PurchaseOrderResource\Actions\ApprovePurchaseOrderEmailAction;
use App\Mail\CustomInvoiceMail;
use App\Models\RecurringInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use App\Filament\Forms\Components\EmailRecipientSelect;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use App\Models\MailSenderProfile;

class ConfigureRecurringInvoiceEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'configure_recurring_invoice_email';
    }

    public function getLabel(): string
    {
        return 'E-mail instellen';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->extraAttributes(['class' => 'recurringInvoiceEmailConfigureAction'])
            ->modalHeading('E-mail instellen')
            ->closeModalByEscaping(false)
            ->schema([
                Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                Group::make()
                    ->extraAttributes(['class' => 'confirm-order-modal custom-form-design', 'style' => 'margin-top: -25px'])
                    ->schema([
                        TextInput::make('from')
                            ->label('Vanaf')
                            ->required()
                            ->disabled()
                            ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel('orders')),

                        EmailRecipientSelect::make('to')
                            ->label('To')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->default(fn ($livewire) => self::getDefaultToRecipients($livewire))
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('cc')
                            ->label('CC')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->default(fn ($livewire) => self::getStoredCcKeys($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('bcc')
                            ->label('BCC')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->default(fn ($livewire) => self::getStoredBccKeys($livewire))
                            ->columnSpanFull(),

                        TextInput::make('subject')
                            ->label('Onderwerp')
                            ->required()
                            ->default(fn ($livewire) => self::getDefaultSubject($livewire)),
                    ]),

                Section::make('Bericht')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        RichEditor::make('message')
                            ->hiddenLabel()
                            ->label('Bericht')
                            ->required()
                            ->default(fn ($livewire) => self::getDefaultMessage($livewire))
                            ->disableToolbarButtons(['attachFiles'])
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data, $livewire): void {
                $record = $livewire->record;
                if (! $record instanceof RecurringInvoice) {
                    return;
                }

                $record->setEmailSubject(trim((string) ($data['subject'] ?? '')));
                $record->setEmailText((string) ($data['message'] ?? ''));
                $record->setEmailCcKeys(self::normalizeRecipientKeys($data['cc'] ?? []));
                $record->setEmailBccKeys(self::normalizeRecipientKeys($data['bcc'] ?? []));
                $record->save();

                Notification::make()
                    ->title('E-mailinstellingen opgeslagen')
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Opslaan')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    private static function normalizeRecipientKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<int, string> */
    private static function getStoredCcKeys($livewire): array
    {
        $record = $livewire->record;
        if (! $record instanceof RecurringInvoice) {
            return [];
        }

        return $record->getEmailCcKeys();
    }

    /** @return array<int, string> */
    private static function getStoredBccKeys($livewire): array
    {
        $record = $livewire->record;
        if (! $record instanceof RecurringInvoice) {
            return [];
        }

        return $record->getEmailBccKeys();
    }

    /** @return array<int, string> */
    private static function getDefaultToRecipients($livewire): array
    {
        $record = $livewire->record;
        if (! $record instanceof RecurringInvoice) {
            return [];
        }

        $customer = $record->billingCustomer;
        if ($customer !== null) {
            $email = $customer->getEmail();
            if (is_string($email) && $email !== '') {
                return ['billing_customer'];
            }
        }

        return [];
    }

    /** @return array<string, string> */
    private static function getRecipientOptions($livewire): array
    {
        $record = $livewire->record;
        $options = ApprovePurchaseOrderEmailAction::getRecipientOptions();

        if ($record instanceof RecurringInvoice) {
            $customer = $record->billingCustomer;
            if ($customer !== null) {
                $options['billing_customer'] = 'Klant: '.$customer->getName().' <'.($customer->getEmail() ?? '—').'>';
            }
        }

        return $options;
    }

    private static function getDefaultSubject($livewire): string
    {
        $record = $livewire->record;
        if (! $record instanceof RecurringInvoice) {
            return '';
        }

        $stored = $record->getEmailSubject();
        if (is_string($stored) && trim($stored) !== '') {
            return $stored;
        }

        $rawSubject = CustomInvoiceMail::getRawTemplateSubjectFromDatabase();

        return $rawSubject !== '' ? $rawSubject : 'Factuur [invoice_number]';
    }

    private static function getDefaultMessage($livewire): string
    {
        $record = $livewire->record;
        if (! $record instanceof RecurringInvoice) {
            return '';
        }

        $stored = $record->getEmailText();
        if (is_string($stored) && trim($stored) !== '') {
            return $stored;
        }

        $rawContent = CustomInvoiceMail::getRawTemplateContentFromDatabase();

        return $rawContent !== '' ? self::revertFirstNamesToPlaceholder($rawContent, $record) : '';
    }

    private static function revertFirstNamesToPlaceholder(string $content, RecurringInvoice $recurring): string
    {
        $firstName = $recurring->billingCustomer?->getFirstName() ?? '';

        if ($firstName !== '') {
            $content = str_replace($firstName, '[first_name]', $content);
        }

        return $content;
    }
}
