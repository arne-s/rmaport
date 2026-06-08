@props(['addressText' => '', 'fittingLocationType' => null, 'phoneDisplayText' => ''])

@if(($fittingLocationType ?? '') === 'phone' && $phoneDisplayText !== '')
<div style="font-size: 11px; margin-left: 10px; margin-top: 0px;text-align: right;">{{ $phoneDisplayText }}</div>
@elseif($addressText !== '')
<div style="font-size: 11px; margin-left: 10px; margin-top: -4px; text-align: right;">{{ $addressText }}</div>
@endif
