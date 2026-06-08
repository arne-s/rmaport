@extends('emails.layouts.company-layout')

@section('content')
    <div id="content">
        {!!  $content  !!}
    </div>

    @if (!empty($showSupportLink))
        @include('emails.partials.need-support')
    @endif
@endsection

<style>
    #content {
        @if (empty($whiteBackground))
        background: #F8F8F8;
        border: 1px solid #B9B9B9;
        @endif
        padding: 45px;
        margin-top: 10px;
        margin-bottom: 30px;
    }

    #content p {
        font-size: 16px;
        margin-bottom: 25px;
        margin-top: 20px;
    }

    #content h2 {
        padding-top: 0;
        margin-top: 0;
        font-size: 26px !important;
        line-height: 24px;
        margin-bottom: 10px;
        font-weight: 600;
    }

    #content em {
        font-style: italic;
    }
</style>
