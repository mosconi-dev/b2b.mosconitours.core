@if (session('status'))
    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
        {{ session('status') }}
    </div>
@endif

@if ($errors->has('rbac'))
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
        {{ $errors->first('rbac') }}
    </div>
@endif
