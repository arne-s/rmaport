<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div class="w-full overflow-hidden">
        <iframe srcdoc="{{ $getState() }}" seamless style="width:100%; height:24rem; display:block;" frameborder="0"></iframe>
    </div>
</x-dynamic-component>
