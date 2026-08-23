{{-- A Space's section tabs (docs/features/help-center.md, P4/P20).

     ONE copy, included by both the Space's screens (`help-center.space`) and its Settings
     screen (`help-center.space-settings`). It was duplicated in the two templates, which is
     how the bar ends up looking like two bars: any change to it — a new section, a different
     active treatment, the right-hand split below — had to be made twice and agreed exactly.

     Settings is pushed to the RIGHT, the way partials/project-tabs.blade.php does it. The
     split is the point: what is on the left are the screens an agent works in, and what is on
     the right is how the Space is configured. Members and Workflow have already moved behind
     Settings (P15, P19), so the left-hand group is now only the working screens and the
     separation the bar shows is a real one.

     Both groups are built from `$sections`, so "active" still means the same on either side.

     The bar is Overview / Inbox / Unassigned / Mine / Draft / Assigned / Closed, then Settings on
     the right (P22) — the same list the Help Center's own navigation carries, narrowed to this
     Space, with this Space's counts. --}}
{{-- `shrink-0` for the same reason the toolbar above it needs one (P81): this is a flex child
     of a scrolling column, and without it a tall page squeezes the tab bar's padding away. --}}
<div class="flex flex-wrap items-center gap-1 px-5 sm:px-8 py-2 border-b border-line shrink-0">
  @foreach ($sections as $s)
    <a href="{{ $s['url'] }}"
       @class([
         'inline-flex items-center gap-1.5 h-7 px-3 rounded-md text-[12px] border',
         // The gap absorbs the wrap on a narrow bar; `ml-auto` only separates the two groups
         // while they share a line, which is exactly when the separation means anything.
         'ml-auto' => $s['key'] === 'settings',
         'border-stroke bg-sel text-brand font-semibold' => $s['active'],
         'border-transparent text-ink hover:bg-hover' => ! $s['active'],
       ])
       @if ($s['active']) aria-current="page" @endif>
      {!! pb_icon($s['icon'], 13) !!}
      {{ $s['label'] }}
      {{-- This Space's own count, on the queues only. Never a zero — the tab still means
           something with no number on it — and the element stays PRESENT and empty so the
           sidebar's refresh has somewhere to write one that was not there on page load. --}}
      <span data-hc-count="{{ $space->id }}:{{ $s['key'] }}"
            @class(['text-[11px] font-semibold tabular-nums', 'text-brand' => $s['active'], 'text-sub' => ! $s['active'], 'hidden' => empty($s['count'])])>{{ $s['count'] ?: '' }}</span>
    </a>
  @endforeach
</div>
