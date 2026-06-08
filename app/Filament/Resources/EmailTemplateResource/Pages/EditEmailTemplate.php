<?php

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Enums\EmailTemplateAudience;
use App\Enums\EmailTemplateType;
use App\Filament\Forms\Components\RichEditor;
use App\Models\EmailTemplateRecipient;
use App\Models\MailSenderProfile;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use App\Filament\Resources\EmailTemplateResource;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Mail\Mailable;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected Mailable $mailable;

    protected static ?string $title = 'E-mail bewerken';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['recipients_to'] = $this->record->recipients()
            ->where('type', EmailTemplateRecipient::TYPE_TO)
            ->first()?->user_id;
        $data['recipients_cc'] = $this->record->recipients()
            ->where('type', EmailTemplateRecipient::TYPE_CC)
            ->pluck('user_id')
            ->toArray();
        $data['recipients_bcc'] = $this->record->recipients()
            ->where('type', EmailTemplateRecipient::TYPE_BCC)
            ->pluck('user_id')
            ->toArray();

        return $data;
    }

    protected function afterSave(): void
    {
        $mailable = $this->record->class::preview();
        $allowOverrideTo = method_exists($mailable, 'allowOverrideTo') && $mailable->allowOverrideTo();

        $data = $this->form->getState();
        $to = $data['recipients_to'] ?? null;
        $cc = is_array($data['recipients_cc'] ?? null) ? $data['recipients_cc'] : [];
        $bcc = is_array($data['recipients_bcc'] ?? null) ? $data['recipients_bcc'] : [];

        if ($allowOverrideTo) {
            $this->record->recipients()->where('type', EmailTemplateRecipient::TYPE_TO)->delete();
        }
        $this->record->recipients()
            ->whereIn('type', [EmailTemplateRecipient::TYPE_CC, EmailTemplateRecipient::TYPE_BCC])
            ->delete();

        if ($allowOverrideTo && $to !== null && $to !== '') {
            $this->record->recipients()->create([
                'user_id' => (int) $to,
                'type' => EmailTemplateRecipient::TYPE_TO,
            ]);
        }
        foreach ($cc as $userId) {
            $this->record->recipients()->create([
                'user_id' => $userId,
                'type' => EmailTemplateRecipient::TYPE_CC,
            ]);
        }
        foreach ($bcc as $userId) {
            $this->record->recipients()->create([
                'user_id' => $userId,
                'type' => EmailTemplateRecipient::TYPE_BCC,
            ]);
        }
    }

    public function form(Schema $schema): Schema
    {
        $this->mailable = $this->record->class::preview();

        $userOptions = User::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => $user->name])
            ->all();
        $allowOverrideTo = method_exists($this->mailable, 'allowOverrideTo') && $this->mailable->allowOverrideTo();

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'emailtemplate-form'])
            ->components([

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Email-Overzicht',
                        'url' => route('filament.app.resources.email-templates.index'),
                    ]),

                Section::make(false)
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                Group::make([
                                    Section::make('Email template: ' . $this->record->getId())
                                        ->schema([
                                    Group::make([
                                        TextInput::make('name')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('Naam')
                                            ->required(),
                                        Html::make('<hr style="border:none;border-top:1px solid #e5e7eb;margin:15px -10px 15px 0">')
                                            ->columnSpanFull(),
                                        TextInput::make('description')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('Omschrijving'),
                                        Select::make('type')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('Type')
                                            ->options(EmailTemplateType::labels())
                                            ->selectablePlaceholder(false)
                                            ->required()
                                            ->default(EmailTemplateType::General->value)
                                            ->native(false),

                                        Select::make('audience')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('Bereik')
                                            ->options(EmailTemplateAudience::labels())
                                            ->selectablePlaceholder(false)
                                            ->required()
                                            ->default(EmailTemplateAudience::Internal->value)
                                            ->native(false),
                                        Html::make('<hr style="border:none;border-top:1px solid #e5e7eb;margin:15px -10px 15px 0">')
                                            ->columnSpanFull(),
                                        TextInput::make('subject')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('Onderwerp')
                                            ->required(),

                                        Html::make('<hr style="border:none;border-top:1px solid #e5e7eb;margin:15px -10px 15px 0">')
                                            ->columnSpanFull(),


                                        Select::make('mail_sender_profile_id')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('Vanaf')
                                            ->options(function (): array {
                                                $profiles = MailSenderProfile::orderByDesc('is_default')
                                                    ->orderBy('name')
                                                    ->get();

                                                $options = [];

                                                foreach ($profiles as $p) {
                                                    $options[$p->id] = $p->name;
                                                }

                                                return $options;
                                            })
                                            ->native(true),
                                        Select::make('recipients_to')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->required()
                                            ->label('To')
                                            ->options($userOptions)
                                            ->visible($allowOverrideTo),
                                        TextInput::make('_to_defined_in_code')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('To')
                                            ->placeholder('De ontvanger(s) is gedefinieerd in de code.')
                                            ->disabled()
                                            ->visible(!$allowOverrideTo)
                                            ->dehydrated(false),
                                        Select::make('recipients_cc')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('CC')
                                            ->multiple()
                                            ->options($userOptions)
                                            ->searchable()
                                            ->preload(),
                                        Select::make('recipients_bcc')
                                            ->inlineLabel()
                                            ->columnSpan(3)
                                            ->label('BCC')
                                            ->multiple()
                                            ->options($userOptions)
                                            ->searchable()
                                            ->preload(),
                                    ]),

                                    RichEditor::make('content')
                                        ->toolbarButtons([
                                            'bold',
                                            'italic',
                                            'underline',
                                            'strike',
                                            'link',
                                            'h2',
                                            'h3',
                                            'codeBlock',
                                            'blockquote',
                                            'bulletList',
                                            'orderedList',
                                            'attachFiles',
                                            'table',
                                            'redo',
                                            'undo',
                                        ]),
                                    TextArea::make('vars')
                                        ->label('Variabelen')
                                        ->rows(5)
                                        ->formatStateUsing(function () {
                                            $recipientKeys = array_keys($this->mailable->getTemplateRecipientVars());
                                            $templateKeys = array_keys($this->mailable->getTemplateVars());
                                            $keys = array_merge(
                                                array_map(fn ($k) => '[' . $k . ']', $recipientKeys),
                                                array_map(fn ($k) => '[' . $k . ']', $templateKeys)
                                            );
                                            return implode(PHP_EOL, array_unique($keys));
                                        }),

                                        ]),
                                ])->columnSpan(6)
                                    ->extraAttributes(['class' => 'emailtemplate-form-group']),
                                Group::make([
                                    Section::make('Voorbeeld')
                                        ->schema([
                                            View::make('preview')
                                                ->reactive()
                                                ->view('filament.partials.email-preview', [
                                                    'subject' => $this->mailable->getTemplateSubject(),
                                                    'url' => route('email.preview', [
                                                        'mailable' => get_class($this->mailable),
                                                        'r' => microtime(),
                                                    ]),
                                                ]),
                                        ]),
                                ])->columnSpan(6)
                                    ->extraAttributes(['class' => 'emailtemplate-preview-column']),
                            ])
                    ]),
            ]);
    }
}
