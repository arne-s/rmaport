<div class="note-documents-panel fi-fo-field-wrp flex flex-col gap-2" wire:key="note-docs-panel-{{ $record?->id ?? 'new' }}-{{ $attachmentsBucket }}">
    <div class="fi-fo-field-label-wrp">
        <label class="note-documents-panel__label fi-fo-field-label">
            Bestanden
        </label>
    </div>
    <div class="fi-fo-field-content-ctn w-full min-w-0">
        @if($record)
            <livewire:documents-block
                :owner-id="$record->id"
                owner-class="{{ \App\Models\Note::class }}"
                collection="attachments"
                :accept-attribute-override="config('documents.accept_attribute') . ',' . config('documents.images_accept_attribute')"
                :upload-zone-key="'note-documents-' . $record->id"
                block-title=""
                :key="'note-documents-block-' . $record->id"
            />
        @else
            <livewire:note-pending-attachments-upload
                :bucket="$attachmentsBucket"
                :key="'note-pending-' . $attachmentsBucket"
            />
        @endif
    </div>
</div>
