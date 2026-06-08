@extends('layouts.quote-approval')

@section('title', config('app.name'))

@push('head')
    <style>
        .quote-approval-pdf-frame {
            border-radius: 0.5rem;
            border: 1px solid rgb(229 231 235);
            overflow: hidden;
            background: #fff;
        }
        .dark .quote-approval-pdf-frame {
            border-color: rgba(255, 255, 255, 0.1);
            background: rgb(3 7 18);
        }
        .quote-approval-submit.fi-btn {
            width: 100%;
            justify-content: center;
        }
        /* Match Filament login submit button (admin.scss .filament-login-page .fi-ac-btn-action[type=submit]) */
        .quote-approval-page .quote-approval-submit.fi-ac-btn-action[type="submit"] {
            padding: 7px 30px !important;
            color: #fff !important;
            background-color: #032d5c !important;
            font-weight: 700;
            border: none !important;
            border-radius: 4px !important;
        }
        .quote-approval-page .quote-approval-submit.fi-ac-btn-action[type="submit"]:hover {
            background-color: #2c2c2c !important;
        }
        @media screen and (max-width: 992px) {
            .quote-approval-page .quote-approval-submit.fi-ac-btn-action[type="submit"] {
                font-weight: 600;
                padding: 10px 30px !important;
            }
        }
    </style>
@endpush

@section('content')
    <div class="flex flex-col gap-5 text-sm">
        <div class="quote-approval-pdf-frame">
            <iframe
                title="Offerte"
                src="{{ route('approve-quote.pdf', ['uuid' => $approval->uuid]) }}"
                class="w-full min-h-[55vh] sm:min-h-[60vh] border-0 block"
            ></iframe>
        </div>



        <form method="post" action="{{ route('approve-quote.submit', ['uuid' => $approval->uuid]) }}" id="quote-approval-form" class="flex flex-col gap-3">
            @csrf


            <div>
                <div class="mb-3 flex flex-row flex-nowrap items-end justify-between gap-x-4 sm:gap-x-6">
                    <div class="min-w-0 flex-1 basis-0">
                        <label for="customer_name" class="block font-medium text-gray-800 dark:text-gray-200 text-sm mb-1">
                            Uw volledige naam:
                        </label>
                        <input
                            type="text"
                            name="customer_name"
                            id="customer_name"
                            value="{{ old('customer_name') }}"
                            required
                            maxlength="255"
                            autocomplete="name"
                            class="fi-input block w-full max-w-md rounded-lg border border-solid border-[#d4d4d9] bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-[#d4d4d9] dark:bg-gray-900 dark:text-gray-100"
                            style="border: 1px solid #d4d4d9"
                        />
                        @error('customer_name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                    <p class="m-0 shrink-0 self-end pb-2 text-right text-sm font-medium text-gray-800 dark:text-gray-200">
                        Datum: {{ now()->timezone(config('app.timezone'))->format('d-m-Y') }}
                    </p>
                </div>
                <p class="font-medium text-gray-800 dark:text-gray-200 text-sm mb-2 m-0">Handtekening:</p>
                <div class="border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 touch-none">
                    <canvas id="sig-canvas" width="480" height="100" class="w-full max-w-full h-[100px] max-h-[100px] block cursor-crosshair"></canvas>
                </div>
                <input type="hidden" name="signature" id="signature-field" value="{{ old('signature') }}">
                @error('signature')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <div class="mt-2 flex flex-row items-center justify-between gap-3">
                    <p class="m-0 min-w-0 flex-1 text-xs leading-snug text-gray-600 dark:text-gray-400">
                        Door te bevestigen ga je akkoord met onze
                        <a
                            href="https://rdmobility.com/doc/algemene-voorwaarden.pdf"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-gray-800 underline decoration-gray-400/80 underline-offset-2 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white"
                        >algemene voorwaarden</a>.
                    </p>
                    <button
                        type="button"
                        id="sig-clear"
                        class="shrink-0 text-xs text-gray-500 underline decoration-gray-400/70 underline-offset-2 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        Wissen
                    </button>
                </div>
            </div>

            <div class="fi-ac fi-width-full">
                <button
                    type="submit"
                    class="fi-btn fi-size-md fi-ac-btn-action quote-approval-submit"
                >
                    Bevestigen
                </button>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
            (function () {
                var canvas = document.getElementById('sig-canvas');
                if (!canvas || !canvas.getContext) return;
                var ctx = canvas.getContext('2d');
                var drawing = false;
                var field = document.getElementById('signature-field');

                function pos(e) {
                    var r = canvas.getBoundingClientRect();
                    var x, y;
                    if (e.touches && e.touches[0]) {
                        x = e.touches[0].clientX - r.left;
                        y = e.touches[0].clientY - r.top;
                    } else {
                        x = e.clientX - r.left;
                        y = e.clientY - r.top;
                    }
                    var scaleX = canvas.width / r.width;
                    var scaleY = canvas.height / r.height;
                    return {x: x * scaleX, y: y * scaleY};
                }

                function start(e) {
                    e.preventDefault();
                    drawing = true;
                    ctx.strokeStyle = '#111827';
                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    var p = pos(e);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                }

                function move(e) {
                    if (!drawing) return;
                    e.preventDefault();
                    var p = pos(e);
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                }

                function end(e) {
                    if (!drawing) return;
                    e.preventDefault();
                    drawing = false;
                    field.value = canvas.toDataURL('image/png');
                }

                canvas.addEventListener('mousedown', start);
                canvas.addEventListener('mousemove', move);
                window.addEventListener('mouseup', end);
                canvas.addEventListener('touchstart', start, {passive: false});
                canvas.addEventListener('touchmove', move, {passive: false});
                canvas.addEventListener('touchend', end);

                document.getElementById('sig-clear').addEventListener('click', function () {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    field.value = '';
                });

                document.getElementById('quote-approval-form').addEventListener('submit', function (ev) {
                    var nameInput = document.getElementById('customer_name');
                    if (!nameInput || !String(nameInput.value || '').trim()) {
                        ev.preventDefault();
                        alert('Vul uw naam in.');
                        return;
                    }
                    if (!field.value || field.value.length < 50) {
                        ev.preventDefault();
                        alert('Plaats een handtekening in het vak.');
                    }
                });
            })();
            });
        </script>
    @endpush
@endsection
