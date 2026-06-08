@props(['travelTime' => null, 'fromAddress' => '', 'toAddress' => ''])

@if($travelTime !== null)
    <div style="font-size: 11px; margin-left: 10px; margin-top: -4px; text-align: right;">
        Schatting reistijd:
        {{ \App\Services\TravelTimeService::formatDuration($travelTime) }}

        <a href="https://www.google.com/maps/dir/?api=1&origin={{ urlencode($fromAddress) }}&destination={{ urlencode($toAddress) }}"
           target="_blank" style="color: blue; text-decoration: underline; font-size: 10px">(Google maps)</a>
        <div>
</div>
    </div>
@elseif($fromAddress !== '' && $toAddress !== '')
    <div style="font-size: 11px; margin-left: 10px; margin-top: -4px; text-align: right; color: #9ca3af">
        Reistijd niet beschikbaar
    </div>
@endif
