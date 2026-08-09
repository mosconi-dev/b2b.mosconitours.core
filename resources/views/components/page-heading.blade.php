@props(['title' => null, 'subtitle' => null, 'titleClass' => ''])

{{--
    Page heading rendered in the top bar, left-aligned with the Support / notification
    controls on the right. Pass the page icon via the `icon` slot; anything in the
    default slot (badges, chips) is appended after the title.
--}}
<div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
    @isset($icon)
        <span class="shrink-0 text-brand-700">{{ $icon }}</span>
    @endisset

    <div class="flex min-w-0 items-baseline gap-2">
        <h1 class="truncate text-base font-semibold tracking-tight text-brand-900 sm:text-lg {{ $titleClass }}">{{ $title }}</h1>

        @if (filled($subtitle))
            <span class="hidden truncate text-sm text-gray-500 xl:block">
                <span class="pr-1 text-gray-400">·</span>{{ $subtitle }}
            </span>
        @endif
    </div>

    {{ $slot }}
</div>
