@php
    /** @var \App\Models\Product|null $record */
@endphp

@if ($record)
    <livewire:documents-block
        :owner-id="$record->id"
        :owner-class="\App\Models\Product::class"
        collection="documents"
        :allowed-mime-types="config('documents.allowed_mime_types', [])"
        :accept-attribute-override="config('documents.accept_attribute')"
        upload-zone-key="product-documents"
        section-id="card-docs"
        block-title=""
        :key="'documents-product-' . $record->id"
    />
@endif
