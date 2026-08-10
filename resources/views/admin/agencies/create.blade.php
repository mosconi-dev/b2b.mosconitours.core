<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Create Agency" />
    </x-slot>

    <x-slot name="back">
        <a href="{{ route('admin.agencies.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to agencies</a>
    </x-slot>

    <div class="max-w-2xl">
        <x-admin.flash />

        <form method="POST" action="{{ route('admin.agencies.store') }}" enctype="multipart/form-data"
              class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            @include('admin.agencies._form', ['agency' => null])

            <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-5">
                <a href="{{ route('admin.agencies.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-800">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    Create Agency
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
