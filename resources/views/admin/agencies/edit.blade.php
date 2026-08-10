<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Edit Agency" :subtitle="$agency->code" />
    </x-slot>

    <x-slot name="back">
        @if (auth()->user()->isPlatformStaff())
            <a href="{{ route('admin.agencies.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to agencies</a>
        @else
            {{-- A member has no list; their own agency page is the level above. --}}
            <a href="{{ route('admin.agencies.show', $agency) }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to {{ $agency->name }}</a>
        @endif
    </x-slot>

    <div class="max-w-2xl space-y-6">
        <x-admin.flash />

        <form method="POST" action="{{ route('admin.agencies.update', $agency) }}" enctype="multipart/form-data"
              class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')

            <h2 class="text-base font-semibold text-brand-900">Details</h2>

            @include('admin.agencies._form')

            <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-5">
                <a href="{{ route('admin.agencies.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-800">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    Save Changes
                </button>
            </div>
        </form>

        @can('delete', $agency)
            <div class="rounded-xl border border-red-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-red-700">Delete Agency</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Archived (soft-deleted) so its history is preserved. Only possible once no users
                    belong to it and no agencies report to it.
                </p>
                <form method="POST" action="{{ route('admin.agencies.destroy', $agency) }}" class="mt-4"
                      onsubmit="return confirm('Delete this agency?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">
                        Delete Agency
                    </button>
                </form>
            </div>
        @endcan
    </div>
</x-app-layout>
