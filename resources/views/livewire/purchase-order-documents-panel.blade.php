<div class="docs-list">
    @foreach ($documents as $doc)
        <div class="doc" wire:key="po-doc-{{ $doc['type'] }}-{{ $doc['id'] }}">
            <div class="doc__left">
                <span class="icon-file" aria-hidden="true">
                    @svgImg('img/icons/document.svg')
                </span>

                <span class="doc__name">
                    @php
                        $docName = ucfirst(__(sprintf('orders.type.%s', $doc['type'])));
                    @endphp
                    @if (! empty($doc['modal']))
                        @php
                            $modalPayload = [
                                'id' => $doc['modal']['id'],
                                $doc['modal']['arg'] => (string) $doc['id'],
                            ];
                        @endphp
                        <button
                            type="button"
                            class="doc__link openDocument"
                            x-on:click.stop="$dispatch('open-modal', {{ json_encode($modalPayload) }})"
                            title="{{ $docName }}"
                        >
                            {{ $docName }}
                        </button>
                    @else
                        <a href="{{ $doc['downloadLink'] }}" title="{{ $docName }}">
                            {{ $docName }}
                        </a>
                    @endif
                </span>
            </div>

            <div class="doc__meta">{{ $doc['uid'] }}</div>

            <div class="doc__meta" @if($doc['sent_at']) title="{{ $doc['sent_at']->translatedFormat('j F Y, H:i') }}" @endif>
                {{ $doc['sent_at']?->translatedFormat('j M Y') ?? '—' }}
            </div>

            @if (! empty($doc['downloadLink']))
                <a href="{{ $doc['downloadLink'] }}">
                    <span class="icon-dl" aria-hidden="true">
                        @svgImg('img/icons/download.svg')
                    </span>
                </a>
            @endif
        </div>
    @endforeach
</div>
