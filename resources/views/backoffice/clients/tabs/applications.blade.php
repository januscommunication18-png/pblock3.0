{{-- Applications (§10). Derived from each workspace's settings (BC-D6), never stored on the
     client — `wiki_enabled` and `help_desk_enabled` are what the product itself reads. --}}
<div class="grid gap-4 sm:grid-cols-2">
  @foreach ($data['applications'] as $app)
    <div class="bg-white border border-line rounded-xl px-4 py-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="text-[14px] font-semibold text-head">{{ $app['label'] }}</div>
          <p class="text-[12px] text-sub mt-1 leading-relaxed">{{ $app['description'] }}</p>
        </div>
        @if (! $app['available'])
          <span class="text-[11px] bg-hover text-sub rounded px-1.5 py-0.5 shrink-0">Soon</span>
        @else
          <x-backoffice.status-badge :status="$app['enabled'] ? 'active' : 'disabled'" class="shrink-0" />
        @endif
      </div>

      <div class="mt-3 pt-3 border-t border-line text-[12px] text-sub">
        @if ($app['available'])
          Enabled in {{ $app['workspaces_enabled'] }} of {{ $app['workspaces_total'] }}
          {{ Str::plural('workspace', $app['workspaces_total']) }}
        @else
          Not yet released
        @endif
      </div>
    </div>
  @endforeach
</div>
