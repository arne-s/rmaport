<?php

namespace App\Filament\Resources;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\NoteStatus;
use App\Enums\NoteType;
use App\Models\Customer;
use App\Models\Note;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TagsInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use App\Livewire\NoteDocumentsPanel;
use App\Models\Order\Main;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class NoteResource extends Resource
{
    protected static ?string $model = Note::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema(static::getFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([])
            ->filters([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getFormSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Group::make()
                    ->extraAttributes(['class' => 'custom-form-design note-modal-top-section'])
                    ->columnSpanFull()
                    ->schema([
                        Hidden::make('attachments_bucket')
                            ->default(fn () => (string) Str::uuid())
                            ->visible(fn (?Note $record): bool => $record === null)
                            ->dehydrated(),

                        Hidden::make('customer_id'),

                        Hidden::make('rma_id'),

                        Select::make('customer_or_company')
                            ->label('Klant')
                            ->preload()
                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                            ->columnSpanFull()
                            ->required()
                            ->disabled(fn (Get $get): bool => filled($get('order_id')) || filled($get('rma_id')))
                            ->validationMessages([
                                'required' => 'Selecteer een klant.',
                            ])
                            ->searchable()
                            ->options(function (): array {
                                return Customer::query()
                                    ->where('status', '!=', CustomerStatus::Initial->value)
                                    ->whereIn('type', array_keys(CustomerType::visibleLabels()))
                                    ->orderBy('name')
                                    ->limit(100)
                                    ->get()
                                    ->mapWithKeys(fn (Customer $c): array => [$c->id => $c->getName()])
                                    ->all();
                            })
                            ->getSearchResultsUsing(function (string $search): array {
                                return Customer::query()
                                    ->where('status', '!=', CustomerStatus::Initial->value)
                                    ->whereIn('type', array_keys(CustomerType::visibleLabels()))
                                    ->where(fn ($q) => $q
                                        ->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%")
                                        ->orWhere('name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%"))
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (Customer $c): array => [$c->id => $c->getName()])
                                    ->all();
                            })
                            ->getOptionLabelUsing(function ($value): string {
                                if (! filled($value)) {
                                    return '';
                                }
                                $customer = Customer::query()->find((int) $value);

                                return $customer ? $customer->getName() : '';
                            })
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $set('customer_id', filled($state) ? (int) $state : null);
                            })
                            ->live(),



                        Select::make('type')
                            ->label('Type')
                            ->required()
                            ->options(NoteType::labels())
                            ->default(NoteType::General->value)
                            ->extraFieldWrapperAttributes(fn (?Note $record, Get $get): array => [
                                'class' => (
                                    filled($get('order_id'))
                                    || filled($get('rma_id'))
                                    || filled($get('product_id'))
                                    || $record?->orders()->exists() === true
                                    || $record?->rmas()->exists() === true
                                    || $record?->products()->exists() === true
                                ) ? 'appears-disabled' : '',
                            ])
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if ($state !== NoteType::Order->value) {
                                    $set('order_id', null);
                                }
                                if ($state !== NoteType::Rma->value) {
                                    $set('rma_id', null);
                                }
                            })
                            ->live(),

                        Select::make('order_id')
                            ->label('Aanvraagnummer')
                            ->extraFieldWrapperAttributes(fn(?Note $record, Get $get): array => [
                                'class' => (filled($get('order_id')) || $record?->orders()->exists() === true) ? 'appears-disabled' : '',
                            ])
                            ->columnSpanFull()
                            ->searchable()
                            ->required()
                            ->preload()
                            ->options(function (Get $get): array {
                                $customerId = $get('customer_id');
                                if (! $customerId) {
                                    return [];
                                }

                                return Main::query()
                                    ->where('customer_id', $customerId)
                                    ->orderByDesc('id')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn(Main $order): array => [$order->id => $order->getDescriptor()])
                                    ->all();
                            })
                            ->afterStateHydrated(function ($state, Set $set): void {
                                if (! filled($state)) {
                                    return;
                                }

                                $order = Main::query()->find($state);
                                if (! $order) {
                                    return;
                                }

                                if ($order->customer_id !== null) {
                                    $set('customer_id', $order->customer_id);
                                    $set('customer_or_company', $order->customer_id);
                                }
                            })
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (! filled($state)) {
                                    return;
                                }

                                $order = Main::query()->find($state);
                                if (! $order) {
                                    return;
                                }

                                if ($order->customer_id !== null) {
                                    $set('customer_id', $order->customer_id);
                                    $set('customer_or_company', $order->customer_id);
                                }
                            })
                            ->visible(fn(Get $get) => $get('type') === NoteType::Order->value && filled($get('customer_id')))
                            ->getSearchResultsUsing(function (string $search, Get $get): array {
                                $customerId = $get('customer_id');
                                if (! $customerId) {
                                    return [];
                                }

                                return Main::query()
                                    ->where('customer_id', $customerId)
                                    ->where(fn ($q) => $q
                                        ->where('uid', 'like', "%{$search}%")
                                        ->orWhere('reference', 'like', "%{$search}%"))
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn(Main $order) => [$order->id => $order->getDescriptor()])
                                    ->all();
                            })
                            ->getOptionLabelUsing(function ($value) {
                                $order = Main::query()->find($value);
                                return $order ? $order->getDescriptor() : '';
                            }),

                        Select::make('status')
                            ->label('Status')
                            ->columnSpanFull()
                            ->selectablePlaceholder(false)
                            ->options(NoteStatus::labels())
                            ->default(NoteStatus::Open->value),
                    ]),

                Textarea::make('content')
                    ->label('Inhoud')
                    ->required()
                    ->rows(5)
                    ->columnSpanFull()
                    ->extraInputAttributes(['class' => 'rich-editor-note-tall'], merge: true),

                Livewire::make(NoteDocumentsPanel::class, fn (Get $get): array => [
                    'attachmentsBucket' => (string) ($get('attachments_bucket') ?? ''),
                ])
                    ->columnSpanFull(),
                Group::make()
                    ->extraAttributes(['class' => 'custom-form-design note-modal-top-section'])
                    ->columnSpanFull()
                    ->schema([
                        TagsInput::make('tagged_users')
                            ->label('Tag collega\'s')
                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                            ->suggestions(function () {
                                return User::query()
                                    ->limit(50)
                                    ->get()
                                    ->map(fn(User $user) => $user->first_name . ' ' . $user->last_name . ' (' . $user->email . ')')
                                    ->toArray();
                            })
                            ->columnSpanFull(),
                    ]),
                TextEntry::make('created_by_at')
                    ->state(function (?Note $record): string {
                        if (!$record) {
                            return '-';
                        }
                        $name = $record->user
                            ? $record->user->first_name . ' ' . $record->user->last_name
                            : '-';
                        $date = $record->created_at
                            ? $record->created_at->format('d-m-Y')
                            : '-';
                        return "Aangemaakt door: {$name} op {$date}.";
                    })
                    ->visible(fn(?Note $record) => $record !== null)
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'note-created-by-line'])
                    ->columnSpan(1),

            ])
        ];

    }

    public static function getFillFormData(Note $record): array
    {
        return [
            'customer_or_company' => $record->customer_id,
            'customer_id' => $record->customer_id,
            'type' => $record->type->value,
            'status' => $record->status->value,
            'order_id' => $record->orders()->first()?->id,
            'rma_id' => $record->rmas()->first()?->id,
            'product_id' => $record->products()->first()?->id,
            'callback_time' => $record->type === NoteType::Callback && isset($record->additional['callback_time'])
                ? $record->additional['callback_time']
                : null,
            'content' => $record->content,
            'tagged_users' => $record->users->map(fn($user) => $user->first_name . ' ' . $user->last_name . ' (' . $user->email . ')')->toArray(),
        ];
    }

    public static function getPages(): array
    {
        return [];
    }
}
