@extends('emails.layouts.minimal-layout')

@section('content')
    Details:
    <pre>
    {{ print_r($debug, true) }}
    </pre>

@endsection

