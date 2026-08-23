{{-- "Do not display an empty table" (docs/features/backoffice-clients.md, §22).

     A heading and a sentence saying what would be here. The same shape the Help Center's
     page-level empty state uses (P78), so the two products do not disagree about what "nothing
     here yet" looks like. --}}
@props(['title', 'message' => null])

<div {{ $attributes->merge(['class' => 'px-6 py-12 text-center']) }}>
    <h3 class="text-[14px] font-semibold text-head">{{ $title }}</h3>
    @if ($message)
        <p class="mt-1.5 text-[13px] text-sub leading-relaxed max-w-[420px] mx-auto">{{ $message }}</p>
    @endif
    {{ $slot ?? '' }}
</div>
