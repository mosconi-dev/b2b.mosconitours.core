{{-- Expects: $agency (Agency|null). The parent form must be enctype="multipart/form-data".
     Progressive enhancement: the <input type="file"> is real and submits on its own if
     Alpine never boots — the drop surface just becomes a plain click-to-browse label. --}}
<div x-data="logoDropzone({ preview: @js($agency?->logoUrl() ?? ''), maxKb: 2048 })" class="mt-1">
    <div x-on:dragover.prevent="dragging = true"
         x-on:dragleave.prevent="dragging = false"
         x-on:drop.prevent="onDrop($event)"
         x-on:click="pick()"
         x-bind:class="dragging ? 'border-blue-500 bg-blue-50' : 'border-gray-300 bg-white hover:bg-gray-50'"
         class="flex cursor-pointer items-center gap-4 rounded-lg border-2 border-dashed px-4 py-5 transition">

        {{-- Preview / placeholder --}}
        <template x-if="preview">
            <img x-bind:src="preview" alt=""
                 class="h-16 w-16 shrink-0 rounded-lg object-contain ring-1 ring-gray-200">
        </template>
        <template x-if="! preview">
            <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-400">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                </svg>
            </span>
        </template>

        <div class="min-w-0">
            <p class="text-sm font-medium text-brand-900">
                <span class="text-blue-600">Click to upload</span> or drag and drop
            </p>
            <p class="mt-0.5 text-xs text-gray-500">JPG, PNG or WEBP · up to 2MB</p>
            <p x-show="fileName" x-text="fileName" x-cloak class="mt-1 truncate text-xs font-medium text-gray-700"></p>
        </div>

        <div class="ml-auto shrink-0">
            <button type="button" x-show="preview" x-cloak x-on:click.stop="clear()"
                    class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-red-600 shadow-sm transition hover:bg-red-50">
                Remove
            </button>
        </div>
    </div>

    <input type="file" name="logo" x-ref="input" x-on:change="onChange()"
           accept="image/jpeg,image/png,image/webp" class="sr-only">

    {{-- Set when the user clears an existing logo, so the server knows to drop it. --}}
    <input type="hidden" name="remove_logo" x-bind:value="removed ? 1 : 0">

    <p x-show="error" x-text="error" x-cloak class="mt-2 text-sm text-red-600"></p>
</div>
