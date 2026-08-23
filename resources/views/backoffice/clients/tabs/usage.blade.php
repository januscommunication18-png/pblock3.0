{{-- Usage (§13).

     The COUNTS are real. The denominators are not: no plan, package or quota exists to read a
     limit from, so a progress bar here would be measuring against a number somebody invented.
     The bar appears the day limits do — see the `limit` key. --}}
<div class="bg-white border border-line rounded-xl">
  <div class="px-4 py-3 border-b border-line text-[13px] font-medium text-ink">Usage</div>
  <div class="px-4 py-4 grid gap-5 sm:grid-cols-2">
    @foreach ($data['usage'] as $u)
      <div>
        <div class="flex items-baseline justify-between gap-3">
          <span class="text-[13px] text-ink">{{ $u['label'] }}</span>
          <span class="text-[14px] font-semibold text-head tabular-nums">
            {{ number_format($u['value']) }}@if ($u['limit'])<span class="text-sub font-normal"> / {{ number_format($u['limit']) }}</span>@endif
          </span>
        </div>

        @if ($u['limit'])
          @php($pct = min(100, (int) round($u['value'] / max(1, $u['limit']) * 100)))
          <div class="mt-2 h-2 rounded-full bg-hover overflow-hidden">
            <div class="h-full bg-brand" style="width: {{ $pct }}%"></div>
          </div>
        @else
          <p class="mt-1 text-[12px] text-faint">No limit set</p>
        @endif
      </div>
    @endforeach
  </div>
</div>
