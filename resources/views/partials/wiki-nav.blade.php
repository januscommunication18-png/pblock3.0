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

{{-- New page. The prominent create action, in the same slot the work-item button occupies
     elsewhere.

     An ANCHOR for the same reason the Collections "+" below is one: the modal is mounted by
     wiki.js, which only loads on the list screens. Where it exists the click is intercepted and
     opens it in place; where it does not, the browser comes here and it opens on arrival. A page
     needs a collection, and the modal is where that is chosen — which is why this can be a
     global action at all. --}}
<a href="{{ route('wiki.home') }}?newpage=1" id="wiki-new-page"
   class="w-full flex items-center justify-center gap-2 px-2 h-9 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold mb-2 transition-colors">
  {!! pb_icon('plus', 15) !!}
  New page
</a>

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
  {{-- An ANCHOR, not a button, and the same shape the Projects "+" already uses.

       The create modal is mounted by wiki.js, which only loads on the four list screens — so on
       a collection or a page this used to be a control that did nothing. The href is the answer:
       everywhere the modal exists the click is intercepted and it opens in place; everywhere it
       does not, the browser goes to /wiki?create=1 and it opens on arrival. Working without JS
       falls out of the same decision. --}}
  <a href="{{ route('wiki.home') }}?create=1" id="wiki-new-collection"
     title="New collection" aria-label="New collection"
     class="absolute right-7 top-1 h-6 w-6 grid place-items-center rounded hover:bg-line text-sub">
    {!! pb_icon('plus', 14) !!}
  </a>
  <div class="mt-0.5 space-y-0.5" data-wiki-collections>
    @forelse ($wikiCollections as $__collection)
      <a href="{{ $__collection['url'] }}"
         class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover {{ $__collection['active'] ? 'bg-sel text-brand' : '' }}">
        {!! pb_icon($__collection['private'] ? 'lock' : 'folder', 14, 'text-sub shrink-0') !!}
        <span class="truncate">{{ $__collection['name'] }}</span>
      </a>
    @empty
      <a href="{{ route('wiki.home') }}?create=1" id="wiki-collections-nav"
         class="flex items-center gap-2 px-2 h-8 rounded-md text-sub hover:bg-hover text-[12px]">
        {!! pb_icon('plus', 14) !!}
        Create your first collection
      </a>
    @endforelse
  </div>
</details>

<div class="mt-3 mx-2 rounded-md border border-line bg-hover px-3 py-2.5">
  <p class="text-[12px] text-sub">
    Collections and pages are being built. Wiki can be switched off in
    <a href="{{ route('settings.general') }}" class="text-brand font-semibold hover:underline">Settings</a>.
  </p>
</div>
