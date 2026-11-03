@props(['wireModel', 'value' => '', 'placeholder' => null, 'field' => null])
@php
    $fieldId = 'rte-'.\Illuminate\Support\Str::random(8);
    $field ??= $wireModel;
@endphp

<div wire:ignore
     x-data="{
        init() {
            const input = this.$refs.input;
            const editor = this.$refs.editor;

            editor.addEventListener('trix-change', () => {
                input.value = editor.value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });

            // No file storage is wired up for description images — drop/paste of a
            // file would otherwise leave a dead attachment sitting in the editor.
            editor.addEventListener('trix-attachment-add', (e) => {
                editor.editor.removeAttachment(e.attachment);
            });

            // Server-driven updates (e.g. 'Generate with AI') — the editor sits in a
            // wire:ignore block so Livewire's own re-render can't push new content into it.
            window.Livewire.on('rich-text-set', ({ field, html }) => {
                if (field === '{{ $field }}' && editor.editor) {
                    editor.editor.loadHTML(html ?? '');
                }
            });
        }
     }">
    <input id="{{ $fieldId }}" type="hidden" x-ref="input" wire:model="{{ $wireModel }}">
    <trix-editor
        x-ref="editor"
        input="{{ $fieldId }}"
        class="trix-content input !h-auto !min-h-[10rem]"
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
    >{!! $value !!}</trix-editor>
</div>
