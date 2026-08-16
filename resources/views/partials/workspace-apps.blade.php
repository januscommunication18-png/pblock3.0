{{-- "Enable apps" — shared by both workspace-creation screens (docs/features/wiki.md).

     ONE copy on purpose. This markup lived twice, in onboarding/workspace.blade.php and
     workspace/create.blade.php, and the two had already drifted: releasing Wiki in one of them
     left the other still rendering it as a locked default, which is exactly the kind of bug
     that survives review because both files look right on their own. --}}
<div class="grid gap-3">
  @foreach (config('workspace.apps') as $appKey => $app)
    @php($isDefault = in_array($appKey, config('workspace.default_apps', []), true))
    @php($wasChosen = in_array($appKey, (array) old('apps', config('workspace.default_apps', [])), true))

    @if ($app['available'] && $isDefault)
      {{-- Projects: what a workspace IS, so it is shown but not offered (WIKI-D3). --}}
      <div class="w-full flex items-start gap-3 p-4 rounded-lg border border-brand/40 bg-sel/40 text-left cursor-default" title="Projects is the default app and can’t be turned off">
        <span class="mt-0.5 h-5 w-5 rounded-md bg-brand grid place-items-center shrink-0">{!! pb_icon('check-on-brand', 12) !!}</span>
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-[14px] font-semibold text-head">{{ $app['label'] }}</span>
            <span class="text-[10px] uppercase tracking-wide bg-brand/10 text-brand rounded px-1.5 py-0.5">Default</span>
          </div>
          <div class="text-[12px] text-sub mt-0.5">{{ $app['description'] }}</div>
        </div>
        <span role="img" aria-label="Read only" class="ml-auto mt-0.5 shrink-0 text-faint">{!! pb_icon('lock', 14) !!}</span>
        <input type="hidden" name="apps[]" value="{{ $appKey }}" />
      </div>

    @elseif ($app['available'])
      {{-- A real choice. Until Wiki shipped, `apps[]` was submitted and no controller read it,
           so this whole step looked like a decision and changed nothing. --}}
      <label class="group w-full flex items-start gap-3 p-4 rounded-lg border border-stroke text-left cursor-pointer hover:bg-hover has-[:checked]:border-brand/40 has-[:checked]:bg-sel/40">
        <input type="checkbox" name="apps[]" value="{{ $appKey }}" class="sr-only peer" @checked($wasChosen) />
        <span class="mt-0.5 h-5 w-5 rounded-md border border-line grid place-items-center shrink-0 peer-checked:bg-brand peer-checked:border-brand">
          {{-- `group-has-`, not `peer-checked:` — this sits a level below the input, and
               peer variants only reach siblings. --}}
          <span class="hidden group-has-[:checked]:block">{!! pb_icon('check-on-brand', 12) !!}</span>
        </span>
        <span class="min-w-0">
          <span class="block text-[14px] font-semibold text-head">{{ $app['label'] }}</span>
          <span class="block text-[12px] text-sub mt-0.5">{{ $app['description'] }}</span>
        </span>
      </label>

    @else
      <div class="w-full flex items-start gap-3 p-4 rounded-lg border border-dashed border-stroke bg-hover/40 text-left opacity-70 cursor-not-allowed select-none" aria-disabled="true">
        <span class="mt-0.5 h-5 w-5 rounded-md border border-line shrink-0"></span>
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-[14px] font-medium text-sub">{{ $app['label'] }}</span>
            <span class="text-[10px] uppercase tracking-wide bg-amber-100 text-amber-700 rounded px-1.5 py-0.5">Coming soon</span>
          </div>
          <div class="text-[12px] text-faint mt-0.5">{{ $app['description'] }}</div>
        </div>
      </div>
    @endif
  @endforeach
</div>
