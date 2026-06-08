<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\OpenMyCalendarAction;
use App\Models\Appointment;
use App\Models\Order\Main;
use App\Support\MailAddressFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MyAppointmentsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.my-appointments-widget';

    public function table(Table $table): Table
    {
        $userId = Auth::id();

        return $table
            ->heading('Mijn afspraken')
            ->headerActions([
                OpenMyCalendarAction::make(),
            ])
            ->query(fn (): Builder => Appointment::query()
                ->with(['order.main', 'locationCustomer.billingAddress'])
                ->where('is_active', true)
                ->when(
                    $userId !== null,
                    fn (Builder $query) => $query->where(function (Builder $query) use ($userId): void {
                        $query
                            ->whereHas('order', fn (Builder $order) => $order->where('advisor_id', $userId))
                            ->orWhereHas('mechanics', fn (Builder $mechanics) => $mechanics->where('users.id', $userId))
                            ->orWhereHas('advisors', fn (Builder $advisors) => $advisors->where('users.id', $userId));
                    }),
                    fn (Builder $query) => $query->whereRaw('0 = 1'),
                )
                ->where('datetime', '>=', now()->startOfDay()))
            ->columns([
                TextColumn::make('datetime')
                    ->label('Datum')
                    ->date('d-m-Y')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '-'),

                TextColumn::make('time_range')
                    ->label('Tijdstip')
                    ->state(fn (Appointment $record): string => self::formatTimeRange($record)),

                TextColumn::make('location')
                    ->label('Locatie')
                    ->state(fn (Appointment $record): string => self::formatLocation($record)),

                ViewColumn::make('main_number')
                    ->label('Aanvraagnummer')
                    ->state(fn (Appointment $record): array => [
                        'label' => self::formatMainNumber($record),
                        'url' => self::mainViewUrl($record),
                    ])
                    ->view('filament.widgets.columns.my-appointments-main-number')
                    ->extraCellAttributes(fn (Appointment $record): array => [
                        'data-main-url' => self::mainViewUrl($record) ?? '',
                        'class' => self::mainViewUrl($record) !== null ? 'my-appointments-main-link-cell cursor-pointer' : '',
                    ]),
            ])
            ->defaultSort('datetime', 'asc')
            ->paginated(false)
            ->emptyStateHeading('Geen afspraken');
    }

    public static function formatTimeRange(Appointment $appointment): string
    {
        $start = $appointment->datetime;
        $end = $appointment->datetime_end;

        if ($end === null || $end->equalTo($start)) {
            return $start->format('H:i');
        }

        return $start->format('H:i') . ' - ' . $end->format('H:i');
    }

    public static function formatLocation(Appointment $appointment): string
    {
        if ($appointment->location_type === 'phone') {
            return 'Telefonisch';
        }

        if ($appointment->location_type === 'custom') {
            $address = $appointment->getLocationAddress();

            return $address !== null
                ? MailAddressFormatter::formatAddress($address)
                : '-';
        }

        return $appointment->getLocationName() ?? '-';
    }

    public static function formatMainNumber(Appointment $appointment): string
    {
        $main = $appointment->order?->main;

        if ($main instanceof Main) {
            return $main->getUidFormatted() ?: '-';
        }

        return $appointment->order?->getUidFormatted() ?: '-';
    }

    public static function mainViewUrl(Appointment $appointment): ?string
    {
        $order = $appointment->order;

        $mainId = $order?->main_id
            ?? $order?->main?->getKey()
            ?? ($order instanceof Main ? $order->getKey() : null)
            ?? ($order?->getKey() !== null
                ? Main::query()->where('order_id', $order->getKey())->value('id')
                : null);

        if ($mainId === null) {
            $mainNumber = self::formatMainNumber($appointment);
            $mainUid = trim(strtok($mainNumber, '/'));

            if ($mainUid !== '' && $mainUid !== '-') {
                $mainId = Main::query()->where('uid', $mainUid)->value('id');
            }
        }

        if ($mainId === null) {
            return null;
        }

        return route('filament.app.resources.mains.view', ['record' => $mainId]);
    }
}
