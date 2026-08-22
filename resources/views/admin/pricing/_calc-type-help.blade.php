{{--
    What the selected calculation type does, with a worked example.

    Sits under the Type select because that is where the question is asked. Every number
    below was computed by CalcTypeGuide through the REAL calculator, not written out —
    help that can disagree with what a booking is charged is worse than no help.

    The block for the type currently selected renders visible; the rest carry x-cloak.
    So without Alpine the form still explains the type it defaults to, rather than
    explaining nothing.

    Expects: $calcTypeGuide, $span, an optional $rule being edited, and a `calcType`
    property on the surrounding x-data.
--}}
@php $initialType = old('calc_type', ($rule ?? null)?->calc_type->value ?? 'fixed'); @endphp

<div class="{{ $span }}">
    @foreach ($calcTypeGuide as $example)
        <div x-show="calcType === @js($example['value'])"
             @if ($example['value'] !== $initialType) x-cloak @endif
             class="rounded-lg border border-gray-200 bg-white px-3 py-2.5">
            <p class="text-xs leading-relaxed text-gray-600">
                <span class="font-semibold text-brand-900">{{ $example['label'] }}</span>@if ($example['restriction'])<span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500">{{ $example['restriction'] }}</span>@endif
                — {{ $example['guidance'] }}
            </p>

            <p class="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-500">
                <span class="font-medium uppercase tracking-wide text-gray-400">Worked example</span><br>
                @if ($example['entered'] === null)
                    Nothing to enter. A supplier rate of <span class="font-mono">{{ $example['net'] }}</span>
                    still sells at <span class="font-mono font-semibold text-brand-900">{{ $example['sells'] }}</span> —
                    but the rule is on the list, saying somebody chose that.
                @else
                    Enter <span class="font-mono font-semibold text-brand-900">{{ $example['entered'] }}</span>
                    against a supplier rate of <span class="font-mono">{{ $example['net'] }}</span>:
                    <span class="font-mono">{{ $example['working'] }}</span>
                    = <span class="font-mono font-semibold text-brand-900">{{ $example['adds'] }}</span> added,
                    selling at <span class="font-mono font-semibold text-brand-900">{{ $example['sells'] }}</span>.
                @endif
            </p>
        </div>
    @endforeach
</div>
