@props(['list', 'boldKeys' => []])
@php
    /** @var array $list */
    /** @var array $boldKeys */
@endphp

<div class="meta">
    <table style="border-top: 1px solid #c0c0c0; padding-top: 10px; margin-bottom: 15px; width: 100%;" cellspacing="0">
        <tr>
            @foreach ($list as $i => $columnData)
                <td style="padding-left: 0; @if ($i === 0) padding-right: 40px; @endif vertical-align: top;" valign="top">
                    <table border="0" cellspacing="0">
                        @foreach ($columnData as $key => $value)
                            @if (!empty($key) && !empty($value))
                                <tr>
                                    <td style="font-size: 12px; white-space: nowrap">
                                        @if (isset($boldKeys) && in_array($key, $boldKeys))
                                            <strong>{{ $key }}: </strong>
                                        @else
                                            {{ $key }}: &nbsp;&nbsp;
                                        @endif
                                    </td>
                                    <td style="font-size: 12px; padding-left: 10px; white-space: nowrap;">
                                        @if (isset($boldKeys) && in_array($key, $boldKeys))
                                            <strong>{{ $value }}</strong>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                </tr>
                            @else
                                <tr>
                                    <td colspan="2">&nbsp;</td>
                                </tr>
                            @endif
                        @endforeach
                    </table>
                </td>
            @endforeach
        </tr>
    </table>
    <style>
        .meta td {
            padding: 0;
            line-height: 20px;
        }
    </style>
</div>
