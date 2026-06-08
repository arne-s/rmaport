@php
    use App\Models\OrderEvent;

    /** @var \Illuminate\Support\Collection<int, OrderEvent> $orderEvents */
@endphp

<section id="order-events-section">
    @if ($orderEvents->isEmpty())
        <p class="order-events-section__empty">Er zijn nog geen gebeurtenissen voor deze aanvraag.</p>
    @else
        <div class="order-events-section__table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Datum</th>
                    <th>Gebruiker</th>
                    <th>Activiteit</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($orderEvents as $event)
                    <tr>
                        <td class="whitespace-nowrap" data-label="Datum">{{ optional($event->created_at)->format('d-m-Y H:i') }}</td>
                        <td data-label="Gebruiker">{{ $event->user?->getName() ?? '[systeem]' }}</td>
                        <td data-label="Activiteit">{{ $event->type }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

