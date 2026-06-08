@props(['list', 'boldKeys' => []])
@php
    /** @var array $list */
    /** @var array $boldKeys */
@endphp

<div class="meta">
    <table style="border-top: 1px solid #c0c0c0; padding-top: 10px" cellspacing="0">
        <tr>
            @foreach ($list as $columnData)
                <td style="padding-right: 90px; padding-left: 0; vertical-align: top" valign="top">
                    <table border="0" cellspacing="0">
                        @foreach ($columnData as $key => $value)
                            @if (!empty($key) && !empty($value))
                                <tr>
                                    <td style="padding-left: 0; white-space: nowrap; font-size: 13px">
                                        @if (isset($boldKeys) && in_array($key, $boldKeys))
                                            <strong>{{ $key }}: </strong>
                                        @else
                                            {{ $key }}: &nbsp;&nbsp;
                                        @endif
                                    </td>
                                    <td style="white-space: nowrap; font-size: 13px; padding-left: 15px">
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
