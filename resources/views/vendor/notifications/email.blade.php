@extends('emails.layouts.default-layout')

@section('content')
    <div style="text-align: center">
        {{-- Intro Lines --}}
        @foreach ($introLines as $line)
            <p>{{ $line }}</p>
        @endforeach

        {{-- Action Button --}}
        @isset($actionText)
            <div style="background: #F8F8F8; border: 2px solid #D8D8D8;
    padding: 45px; text-align: center; margin-top: 10px; margin-bottom: 30px">
                @include('emails.partials.button', [
                            'url' => $actionUrl,
                            'label' => $actionText
                            ])
            </div>
        @endisset

        {{-- Outro Lines --}}
        @foreach ($outroLines as $line)
            <p>{{ $line }}</p>
        @endforeach
    </div>
@endsection
