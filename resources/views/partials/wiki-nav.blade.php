{{-- The Wiki's own sidebar (docs/features/wiki.md).

     Replaces the sidebar BODY while inside /wiki, and only the body: the rail, the workspace
     header, the collapse control and the drawer behaviour are the same panel, so they stay in
     app-sidebar.blade.php rather than being copied into a second one that would drift.

     "When Wiki is enabled, users get a focused navigation experience for finding and organizing
     documentation" — so Projects, Inbox and Drafts are deliberately absent here. This is a
     different room, not the same room with extra doors. --}}
{{-- `$wikiSections` and `$wikiCollections` come from the composer in AppServiceProvider, backed
     by App\Services\WikiNavigation. They are NOT passed by the controllers: this partial rides
     along with the shared sidebar on every Wiki screen, and the collections list has to be
     permission-filtered in one place rather than in each of the four that render it. --}}

{{-- New Page. The prominent create action the requirements ask for, in the same slot the
     work-item button occupies elsewhere. Disabled until pages exist — shown rather than
     hidden, because its absence would read as "the Wiki cannot create anything". --}}
<button type="button" disabled
        title="Pages are being built"
        class="w-full flex items-center justify-center gap-2 px-2 h-9 rounded-md bg-brand/40 text-white text-[13px] font-semibold mb-2 cursor-not-allowed">
  {!! pb_icon('plus', 15) !!}
  New page
</button>

{{-- Every section is a real screen now, so one row template rather than the three-way fork this
     had while Shared, Private and Archived were still a roadmap. The Collections row is dropped
     by the service — the disclosure below carries that name. --}}
@foreach ($wikiSections as $__section)
  <a href="{{ $__section['href'] }}" title="{{ $__section['blurb'] }}"
     @if ($__section['active']) aria-current="page" @endif
     class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover {{ $__section['active'] ? 'bg-sel text-brand' : '' }}">
    {!! pb_icon($__section['icon'], 15) !!}{{ $__section['label'] }}
  </a>
@endforeach

{{-- Collections — the same disclosure the Projects group uses in the app sidebar: a
     <details> that remembers nothing, a chevron that rotates with it (the `.pb-chev` rule
     lives in app-sidebar.blade.php, which wraps this), and a "+" that is a SIBLING of
     <summary> rather than inside it, so no interactive element is nested in the disclosure
     control (a11y).

     Pages will nest underneath each collection here — "Expand. Navigate. Continue working." --}}
<details open class="mt-3 relative">
  <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
    <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Collections</span>
    {!! pb_icon('chevron-down', 14, 'pb-chev ml-auto text-faint transition-transform') !!}
  </summary>
  <button type="button" id="wiki-new-collection" title="New collection" aria-label="New collection"
          class="absolute right-7 top-1 h-6 w-6 grid place-items-center rounded hover:bg-line text-sub">
    {!! pb_icon('plus', 14) !!}
  </button>
  <div class="mt-0.5 space-y-0.5" data-wiki-collections>
    @forelse ($wikiCollections as $__collection)
      <a href="{{ $__collection['url'] }}"
         class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover {{ $__collection['active'] ? 'bg-sel text-brand' : '' }}">
        {!! pb_icon($__collection['private'] ? 'lock' : 'folder', 14, 'text-sub shrink-0') !!}
        <span class="truncate">{{ $__collection['name'] }}</span>
      </a>
    @empty
      <button type="button" id="wiki-collections-nav"
              class="w-full flex items-center gap-2 px-2 h-8 rounded-md text-sub hover:bg-hover text-[12px]">
        {!! pb_icon('plus', 14) !!}
        Create your first collection
      </button>
    @endforelse
  </div>
</details>

<div class="mt-3 mx-2 rounded-md border border-line bg-hover px-3 py-2.5">
  <p class="text-[12px] text-sub">
    Collections and pages are being built. Wiki can be switched off in
    <a href="{{ route('settings.general') }}" class="text-brand font-semibold hover:underline">Settings</a>.
  </p>
</div>
