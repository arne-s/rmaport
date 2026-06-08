@props(['addressText' => ''])

@if($addressText !== '')
<div style="font-size: 11px; border: 1px solid red; margin-left: 10px; margin-top: -15px; text-align: right; padding: 5px;">{{ $addressText }}</div>
@endif
