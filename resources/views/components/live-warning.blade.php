@props(['live' => false])

{{--
    The last thing read before an irreversible press.

    One component rather than four copies: it sits on both wizards and on both recovery
    panels, and the four are only worth having if an agent recognises the same red box
    every time. The lead sentence is fixed here; the slot carries what this particular
    press will actually do, because a ticket and a room are undone very differently.

    Renders nothing outside a live environment — a test booking spends nothing, and a
    warning that cries wolf on every test press is one nobody reads on the day it counts.
--}}
@if ($live)
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800']) }}>
        <strong>This is a LIVE booking.</strong> {{ $slot }}
    </div>
@endif
