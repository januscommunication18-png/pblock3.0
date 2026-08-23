{{-- A Back Office status badge (docs/features/backoffice-clients.md, §21).

     ONE component for every status in the Back Office, because §21 asks for exactly that —
     "these status components should be reusable throughout". The vocabulary lives here rather
     than at each call site so that Active is the same green on the Clients list, the client
     header and any module built later.

     Anything unrecognised falls through to the neutral treatment rather than rendering nothing:
     an unknown status is still information, and a blank cell is not. --}}
@props(['status'])

@php
    $key = \Illuminate\Support\Str::of($status)->lower()->replace(' ', '_')->toString();

    $tone = match ($key) {
        'active'            => 'bg-success/10 text-success border-success/20',
        'trial'             => 'bg-brand/10 text-brand border-brand/20',
        'disabled',
        'cancelled'         => 'bg-hover text-sub border-line',
        'suspended'         => 'bg-warning/10 text-warning border-warning/25',
        'pending_deletion'  => 'bg-danger/10 text-danger border-danger/25',
        default             => 'bg-hover text-sub border-line',
    };

    $label = match ($key) {
        'pending_deletion' => 'Pending Deletion',
        default => \Illuminate\Support\Str::of($key)->replace('_', ' ')->title()->toString(),
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center h-6 px-2 rounded-md border text-[12px] font-medium whitespace-nowrap '.$tone]) }}>
    {{ $label }}
</span>
