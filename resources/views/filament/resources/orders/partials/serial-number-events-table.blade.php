@php
    use App\Models\SerialNumberEvent;

    /** @var \Illuminate\Support\Collection<int, SerialNumberEvent> $serialNumberEvents */
@endphp

<section id="order-events-section">
    @if ($serialNumberEvents->isEmpty())
        <p class="order-events-section__empty">Er zijn nog geen gebeurtenissen voor dit serienummer.</p>
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
                @foreach ($serialNumberEvents as $event)
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

