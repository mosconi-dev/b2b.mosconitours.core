{{--
    Booking progress stepper. Pass $current (1–5) for a static render (e.g. the
    flights list, where Select Flight is active); omit it to bind to the wizard's
    reactive Alpine `step`.
--}}
@php
    $current = $current ?? null;
    $steps = [
        ['n' => 1, 'label' => 'Select Flight', 'icon' => 'M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5'],
        ['n' => 2, 'label' => 'Guest Details', 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
        ['n' => 3, 'label' => 'Add-ons', 'icon' => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25a2.25 2.25 0 01-2.25-2.25v-2.25z'],
        ['n' => 4, 'label' => 'Payment', 'icon' => 'M21 12a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3'],
        ['n' => 5, 'label' => 'Confirmation', 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];
    $complete = 'bg-blue-600 text-white';
    $active = 'bg-blue-600 text-white ring-4 ring-blue-100';
    $future = 'bg-gray-100 text-gray-400';
@endphp

<nav>
    <ol class="flex items-start">
        @foreach ($steps as $s)
            <li class="flex flex-1 flex-col items-center">
                <div class="flex w-full items-center">
                    {{-- line before --}}
                    @if ($loop->first)
                        <div class="h-0.5 flex-1 bg-transparent"></div>
                    @elseif (is_null($current))
                        <div class="h-0.5 flex-1" :class="step > {{ $s['n'] - 1 }} ? 'bg-blue-500' : 'bg-gray-200'"></div>
                    @else
                        <div class="h-0.5 flex-1 {{ $current > $s['n'] - 1 ? 'bg-blue-500' : 'bg-gray-200' }}"></div>
                    @endif

                    {{-- circle --}}
                    @if (is_null($current))
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full transition"
                             :class="step > {{ $s['n'] }} ? '{{ $complete }}' : (step === {{ $s['n'] }} ? '{{ $active }}' : '{{ $future }}')">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $s['icon'] }}" /></svg>
                        </div>
                    @else
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full transition {{ $current > $s['n'] ? $complete : ($current === $s['n'] ? $active : $future) }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $s['icon'] }}" /></svg>
                        </div>
                    @endif

                    {{-- line after --}}
                    @if ($loop->last)
                        <div class="h-0.5 flex-1 bg-transparent"></div>
                    @elseif (is_null($current))
                        <div class="h-0.5 flex-1" :class="step > {{ $s['n'] }} ? 'bg-blue-500' : 'bg-gray-200'"></div>
                    @else
                        <div class="h-0.5 flex-1 {{ $current > $s['n'] ? 'bg-blue-500' : 'bg-gray-200' }}"></div>
                    @endif
                </div>

                {{-- label --}}
                @if (is_null($current))
                    <span class="mt-2 whitespace-nowrap text-xs font-medium" :class="step >= {{ $s['n'] }} ? 'text-brand-900' : 'text-gray-400'">{{ $s['label'] }}</span>
                @else
                    <span class="mt-2 whitespace-nowrap text-xs font-medium {{ $current >= $s['n'] ? 'text-brand-900' : 'text-gray-400' }}">{{ $s['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
