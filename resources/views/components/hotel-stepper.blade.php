@props(['current' => null])

{{--
    The hotel booking progress stepper.

    A component rather than a partial because the step list has to be defined and used
    in the same scope: an included view renders in a child scope, so assigning the list
    there never reaches the page that pulled it in.

    (Note for future edits: Blade compiles directives even inside these comments, so
    naming one here with its leading sigil produces a parse error rather than prose.)

    Five steps, matching the flights wizard position for position, so an agent who has
    learned one has learned both. They differ only where the products do: a hotel has
    rooms inside it, so choosing one takes two steps, and there is nothing to upsell
    where flights sell seats and baggage.

    Pass `current` for a static render; omit it to bind to the page's reactive Alpine
    `step`.
--}}
@php
    $steps = [
        ['n' => 1, 'label' => 'Select Hotel', 'icon' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21'],
        ['n' => 2, 'label' => 'Select Room', 'icon' => 'M3 8.25V18a2.25 2.25 0 002.25 2.25h13.5A2.25 2.25 0 0021 18V8.25M3 8.25V6a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 6v2.25M3 8.25h18M7.5 12a1.5 1.5 0 103 0 1.5 1.5 0 00-3 0zm4.5 0h4.5'],
        ['n' => 3, 'label' => 'Guest Details', 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
        ['n' => 4, 'label' => 'Payment', 'icon' => 'M21 12a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3'],
        ['n' => 5, 'label' => 'Confirmation', 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];
@endphp

@include('bookings._stepper', ['steps' => $steps, 'current' => $current])
