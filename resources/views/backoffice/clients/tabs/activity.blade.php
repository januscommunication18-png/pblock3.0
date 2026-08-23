{{-- Activity (§15). Grouped by day, the way the requirement's own example reads. --}}
<div class="bg-white border border-line rounded-xl">
  @if (empty($data['activities']))
    <x-backoffice.empty-state title="No activity yet"
      message="Client-level events appear here as they happen." />
  @else
    <div class="px-4 py-4">
      @php($grouped = collect($data['activities'])->groupBy('day'))
      @foreach ($grouped as $day => $rows)
        <div class="@if (! $loop->first) mt-6 @endif">
          <div class="text-[12px] font-semibold text-faint uppercase tracking-wide">{{ $day }}</div>
          <ul class="mt-2 space-y-2">
            @foreach ($rows as $a)
              <li class="flex items-start gap-3">
                <span class="mt-1.5 h-1.5 w-1.5 rounded-full bg-stroke shrink-0"></span>
                <div class="min-w-0">
                  <div class="text-[13px] text-ink">{{ $a['description'] }}</div>
                  <div class="text-[12px] text-faint mt-0.5">{{ $a['actor'] }} &middot; {{ $a['at'] }}</div>
                </div>
              </li>
            @endforeach
          </ul>
        </div>
      @endforeach
    </div>
  @endif
</div>
