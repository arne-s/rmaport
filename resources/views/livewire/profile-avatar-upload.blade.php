<div
    class="profile-avatar-upload fi-fo-field fi-fo-field-has-inline-label"
    wire:key="profile-avatar-upload-{{ $userId }}"
>
    <div class="fi-fo-field-label-col fi-vertical-align-center">
        <div class="fi-fo-field-label-ctn">
            <div class="fi-fo-field-label">
                <span class="fi-fo-field-label-content">Profielfoto</span>
            </div>
        </div>
    </div>

    <div class="fi-fo-field-content-col">
        @php
            $hasPhoto = filled($avatarUrl);
        @endphp
        <div
            class="profile-avatar-upload__circle relative shrink-0 overflow-hidden rounded-full border border-solid border-[#f0f0f0] {{ $hasPhoto ? 'bg-gray-100' : 'bg-neutral-200' }}"
            style="z-index: 0; width: 100px; height: 100px;"
        >
            @if ($hasPhoto)
                <img
                    src="{{ $avatarUrl }}"
                    alt=""
                    class="absolute inset-0 z-0 w-full h-full object-cover"
                    loading="lazy"
                    width="100"
                    height="100"
                />
            @endif

            <div
                class="pointer-events-none absolute inset-0 z-[1] rounded-full bg-neutral-950/40 opacity-0 transition-opacity duration-200 ease-in-out"
                wire:loading.class="opacity-100"
                wire:target="avatar"
            ></div>

            <div
                class="absolute inset-x-0 bottom-0 z-10 flex items-center justify-center gap-2 px-1 pb-1.5 pt-5 bg-gradient-to-t {{ $hasPhoto ? 'from-black/55' : 'from-neutral-400/45' }} to-transparent"
            >
                <input
                    id="profile-avatar-file-{{ $userId }}"
                    type="file"
                    class="sr-only"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    wire:model="avatar"
                />

                <button
                    type="button"
                    class="avatar-upload__action inline-flex size-[1.45rem] shrink-0 cursor-pointer items-center justify-center rounded-md border-0 p-0 leading-none {{ $hasPhoto ? '!bg-neutral-900/92 shadow-md ring-1 ring-white/25 text-white drop-shadow-[0_1px_3px_rgb(0_0_0/90)] hover:!bg-neutral-950' : '!bg-neutral-500/95 shadow-sm ring-1 ring-neutral-600/25 text-white hover:!bg-neutral-600' }} disabled:cursor-not-allowed disabled:opacity-45 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-white/80"
                    title="Foto uploaden"
                    aria-label="Foto uploaden"
                    onclick="document.getElementById('profile-avatar-file-{{ $userId }}').click()"
                    wire:loading.attr="disabled"
                    wire:target="avatar"
                >
                    <x-heroicon-s-plus-circle class="pointer-events-none size-[1.305rem] shrink-0" aria-hidden="true" />
                </button>

                @if ($hasCustomAvatar)
                    <button
                        type="button"
                        class="avatar-upload__action inline-flex size-[1.45rem] shrink-0 cursor-pointer items-center justify-center rounded-md border-0 p-0 leading-none !bg-neutral-900/92 text-rose-200 shadow-md ring-1 ring-rose-300/40 drop-shadow-[0_1px_3px_rgb(0_0_0/90)] hover:!bg-neutral-950 hover:text-white disabled:cursor-not-allowed disabled:opacity-45 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-white/80"
                        title="Foto verwijderen"
                        aria-label="Foto verwijderen"
                        wire:click="removeAvatar"
                        wire:loading.attr="disabled"
                        wire:target="removeAvatar,avatar"
                    >
                        <x-heroicon-o-trash class="pointer-events-none size-[1.305rem] shrink-0" aria-hidden="true" />
                    </button>
                @else
                    <button
                        type="button"
                        class="avatar-upload__action inline-flex size-[1.45rem] shrink-0 cursor-not-allowed items-center justify-center rounded-md border-0 p-0 leading-none !bg-neutral-500/75 text-white/65 shadow-sm ring-1 ring-neutral-600/20"
                        title="Geen foto om te verwijderen"
                        disabled
                        aria-hidden="true"
                        tabindex="-1"
                    >
                        <x-heroicon-o-trash class="pointer-events-none size-[1.305rem] shrink-0 opacity-80" />
                    </button>
                @endif
            </div>
        </div>

        @error('avatar')
            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
        @enderror
    </div>
    <style>
        .fi-main {
            padding-bottom: 80px;
        }
    </style>
</div>


