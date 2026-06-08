@extends('emails.layouts.default-layout')

@section('content')
    <div id="content">
        {!! $body !!}
    </div>
@endsection

<style>
    #content {
        background: #F8F8F8;
        border: 1px solid #B9B9B9;
        padding: 45px;
        margin-top: 10px;
        margin-bottom: 30px;
    }

    #content p {
        font-size: 16px;
        margin-bottom: 25px;
        margin-top: 20px;
    }
</style>
