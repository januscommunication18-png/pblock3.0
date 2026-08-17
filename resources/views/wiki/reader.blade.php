{{-- The reading layout for a collection (docs/features/wiki.md).

     ONE template for both the authenticated Preview and the published public page. They show
     the same thing to different people, and this feature has already been bitten twice by the
     same markup living in two files and quietly drifting apart.

     `$preview` is the only difference: it adds the banner saying this is not the live page.

     Header full width; below it a 3/9 split — navigation on the left, the document on the
     right. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $current?->title ?? ($cover['is_enabled'] ? ($cover['title'] ?: $collection->name) : $collection->name) }} — {{ $collection->name }}</title>
  @unless ($preview)
    <meta name="description" content="{{ $collection->description }}" />
  @else
    {{-- A preview is a working copy of something not published; it has no business in an index. --}}
    <meta name="robots" content="noindex" />
  @endunless

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {{-- The rich-text read styles the page bodies were written in. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/css/work-items.css') }}" />
</head>
{{-- The viewport IS the page: header pinned, sidebar and document scrolling independently.
     `min-h-screen` was letting the whole document scroll as one, which took the navigation
     away with it. --}}
<body class="bg-white text-ink text-[13px] h-screen flex flex-col overflow-hidden">

  @if ($preview)
    <div class="bg-amber-50 border-b border-amber-200 px-5 sm:px-8 py-2 text-[12px] text-amber-900">
      <b>Preview.</b> This is how the collection reads to somebody opening it.
      @if ($collection->isPublished())
        It is published at <a href="{{ $publicUrl }}" class="underline font-semibold">{{ $publicUrl }}</a>.
      @else
        It is not published, so nobody outside the workspace can reach it yet.
      @endif
    </div>
  @endif

  {{-- Header: full width, as asked. --}}
  {{-- Sticky, so the collection you are in stays named while you read down a long page. --}}
  <header class="border-b border-line shrink-0 bg-white">
    <div class="w-full px-5 sm:px-8 h-14 flex items-center gap-3">
      <span class="h-8 w-8 rounded-md bg-slate-700 text-white grid place-items-center text-[13px] font-semibold bg-cover bg-center shrink-0"
            @if ($workspace->logo_url) style="background-image:url('{{ $workspace->logo_url }}')" @endif>
        @unless ($workspace->logo_url){{ $workspace->initial() }}@endunless
      </span>
      <div class="min-w-0">
        <div class="text-[14px] font-semibold text-head truncate leading-tight">{{ $collection->name }}</div>
        <div class="text-[11px] text-sub truncate leading-tight">{{ $workspace->name }}</div>
      </div>

      @if ($current)
        <span class="ml-auto hidden sm:block text-[12px] text-faint truncate max-w-[40%]">{{ $current->title }}</span>
      @endif
    </div>
  </header>

  {{-- Navigation at a FIXED width — the same 240px the application's own sidebar uses, so the
       two feel like one product. A fraction of the viewport made it a 430px column of short
       titles on a wide screen, which is space the document wanted.

       Each side scrolls on its own: the navigation is only useful if it is still there when
       you are halfway down a long page. --}}
  <div class="flex-1 min-h-0 flex flex-col lg:flex-row">

    <nav data-nav="{{ $collection->id }}"
         class="w-full lg:w-60 shrink-0 border-b lg:border-b-0 lg:border-r border-line
                px-4 py-6 overflow-y-auto lg:h-full">
      {{-- The way back to the cover. Without it the front door is a screen you can only reach
           by deleting the query string out of the address bar. --}}
      @if ($cover['is_enabled'])
        <a href="{{ $coverUrl }}"
           @class([
             'flex items-center gap-2 px-2 py-1.5 mb-4 rounded-md text-[13px] truncate',
             'bg-sel text-brand font-medium' => $onCover,
             'text-ink hover:bg-hover' => ! $onCover,
           ])>{{ $cover['title'] ?: $collection->name }}</a>
      @endif

      @if ($collection->description)
        <p class="text-[12px] text-sub mb-4">{{ $collection->description }}</p>
      @endif

      {{-- Filters the list below. Plain JS and no endpoint: everything it searches is already
           on the page, and a round trip to hide four links would be theatre. --}}
      <input type="search" id="wiki-nav-filter" placeholder="Filter pages…"
             aria-label="Filter pages"
             class="w-full h-9 px-3 mb-4 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint
                    outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />

      @forelse ($sections as $section)
        @php
          // The ungrouped pseudo-section has no id of its own; 0 is a key nothing else can take.
          $key = 's'.($section['id'] ?? 0);
          // Whichever section holds the page being read stays open whatever was collapsed last
          // time — collapsing the nav must never hide the place you are standing in.
          $holdsCurrent = $current && (
            collect($section['pages'])->contains(fn ($p) => $p->id === $current->id)
            || collect($section['children'] ?? [])
                ->flatMap(fn (array $c) => $c['pages'])
                ->contains(fn ($p) => $p->id === $current->id)
          );
        @endphp
        <div class="mb-5" data-nav-section data-nav-node data-nav-key="{{ $key }}"
             @if ($holdsCurrent) data-nav-current @endif>
          {{-- The heading IS the control. A separate chevron beside a label that does nothing
               is two targets for one action, and the small one is the only one that works. --}}
          <button type="button" data-nav-toggle aria-expanded="true" aria-controls="nav-{{ $key }}"
                  class="w-full flex items-center gap-1.5 py-0.5 text-left rounded hover:text-sub">
            <span data-nav-chevron class="shrink-0 text-faint transition-transform duration-150">
              {!! pb_icon('chevron-down', 11) !!}
            </span>
            <span class="text-[11px] font-semibold text-faint uppercase tracking-wide truncate">{{ $section['name'] }}</span>
            @if (! empty($section['label']))
              <span class="text-[10px] uppercase tracking-wide bg-hover text-sub rounded px-1.5 py-0.5">{{ $section['label'] }}</span>
            @endif
          </button>

          <div id="nav-{{ $key }}" data-nav-body>
          @if (! empty($section['short_description']))
            <p class="text-[12px] text-faint mt-0.5 pl-4">{{ $section['short_description'] }}</p>
          @endif

          <ul class="mt-1.5 space-y-0.5">
            @foreach ($pageTree($section['pages']) as $node)
              @include('partials.wiki-reader-nav-item', ['node' => $node])
            @endforeach
            @if (! count($section['pages']) && ! count($section['children'] ?? []))
              <li class="px-2 py-1.5 text-[12px] text-faint">No pages yet.</li>
            @endif
          </ul>

          {{-- Sub-sections, indented under the heading they belong to. Same markup, one step
               quieter: the nav is a table of contents, and a second heading style competing
               with the first would read as two kinds of thing rather than two levels. --}}
          @foreach ($section['children'] ?? [] as $child)
            @php
              $childKey = 's'.$child['id'];
              $childHoldsCurrent = $current && collect($child['pages'])->contains(fn ($p) => $p->id === $current->id);
            @endphp
            <div class="mt-3 pl-2 border-l border-line" data-nav-section data-nav-node data-nav-key="{{ $childKey }}"
                 @if ($childHoldsCurrent) data-nav-current @endif>
              <button type="button" data-nav-toggle aria-expanded="true" aria-controls="nav-{{ $childKey }}"
                      class="w-full flex items-center gap-1.5 py-0.5 text-left rounded hover:text-ink">
                <span data-nav-chevron class="shrink-0 text-faint transition-transform duration-150">
                  {!! pb_icon('chevron-down', 11) !!}
                </span>
                <span class="text-[11px] font-semibold text-sub truncate">{{ $child['name'] }}</span>
                @if (! empty($child['label']))
                  <span class="text-[10px] uppercase tracking-wide bg-hover text-sub rounded px-1.5 py-0.5">{{ $child['label'] }}</span>
                @endif
              </button>

              <div id="nav-{{ $childKey }}" data-nav-body>
              @if (! empty($child['short_description']))
                <p class="text-[12px] text-faint mt-0.5 pl-4">{{ $child['short_description'] }}</p>
              @endif

              <ul class="mt-1 space-y-0.5">
                @foreach ($pageTree($child['pages']) as $node)
                  @include('partials.wiki-reader-nav-item', ['node' => $node])
                @endforeach
                @if (! count($child['pages']))
                  <li class="px-2 py-1.5 text-[12px] text-faint">No pages yet.</li>
                @endif
              </ul>
              </div>
            </div>
          @endforeach
          </div>
        </div>
      @empty
        <p class="text-[13px] text-faint">This collection has no pages yet.</p>
      @endforelse

      <p id="wiki-nav-empty" class="hidden text-[12px] text-faint">Nothing matches that.</p>
    </nav>

    @php
      /* Alignment and the contents column answer to the cover only when there IS one. Every
         collection written before this feature reads flush left with its headings listed, and
         a default stored on a row nobody configured must not quietly restyle all of them. */
      $align = $cover['is_enabled'] ? $cover['content_alignment'] : 'left';
      $showToc = count($outline['toc']) > 1 && (! $cover['is_enabled'] || $cover['on_this_page_enabled']);

      /* The cover is a grid, not prose, so it is given more room than the 820px reading measure
         the pages are held to — three columns inside 760px is three columns of broken words. */
      $cardColumns = match ($cover['card_layout']) {
        'two' => 'sm:grid-cols-2',
        'three' => 'sm:grid-cols-2 lg:grid-cols-3',
        default => 'sm:grid-cols-2 xl:grid-cols-3',
      };
    @endphp

    <main class="flex-1 min-w-0 overflow-y-auto px-5 sm:px-10 py-10 flex gap-10">
      <div class="min-w-0 flex-1">
      <div @class([
        'max-w-[760px]' => ! $onCover,
        'max-w-[980px]' => $onCover,
        'mr-auto' => $align === 'left',
        'mx-auto' => $align === 'center',
        'ml-auto' => $align === 'right',
      ])>
      @if ($onCover)
        {{-- The front door (docs/features/wiki-cover-page.md). --}}
        <h1 class="text-[34px] font-bold text-head tracking-tight">{{ $cover['title'] ?: $collection->name }}</h1>
        @if ($cover['short_description'])
          <p class="text-[15px] text-sub mt-3 max-w-[620px]">{{ $cover['short_description'] }}</p>
        @endif

        {{-- The cards, built from what the collection already contains — a section each, or a
             page each when nobody has made sections yet. See WikiReader::coverCards().

             The WHOLE card is the link: one anchor, with the Explore affordance inside it. An
             anchor inside an anchor is invalid markup and unreachable by keyboard. --}}
        @if (count($coverCards))
          <div class="grid gap-4 mt-8 {{ $cardColumns }}">
            @foreach ($coverCards as $card)
              <a href="{{ $pageUrl($card['page']) }}"
                 class="group flex flex-col rounded-xl border border-line p-5 transition-colors
                        hover:border-stroke hover:bg-hover/50 focus:outline-none focus-visible:ring-2
                        focus-visible:ring-brand focus-visible:ring-offset-2">
                <span class="text-[15px] font-semibold text-head group-hover:text-brand">{{ $card['title'] }}</span>

                @if ($card['description'])
                  <span class="text-[13px] text-sub mt-1.5">{{ $card['description'] }}</span>
                @endif

                @if ($card['count'])
                  <span class="text-[12px] text-faint mt-1.5">
                    {{ $card['count'] }} {{ Str::plural('page', $card['count']) }}
                  </span>
                @endif

                <span class="text-[13px] font-semibold text-brand mt-4 inline-flex items-center gap-1.5">
                  Explore <span aria-hidden="true">&rarr;</span>
                </span>
              </a>
            @endforeach
          </div>
        @elseif ($firstPage)
          <a href="{{ $pageUrl($firstPage) }}"
             class="inline-flex items-center gap-2 mt-8 h-10 px-4 rounded-md bg-brand hover:bg-brand-dark
                    text-white text-[13px] font-semibold">
            Start reading <span aria-hidden="true">&rarr;</span>
          </a>
        @else
          {{-- FR-WC-033 — an intentional empty state, not a broken grid. --}}
          <p class="text-[13px] text-faint mt-8">There are no pages in this collection yet.</p>
        @endif
      @elseif ($current)
        <h1 class="text-[30px] font-bold text-head tracking-tight">{{ $current->title }}</h1>
        @if ($current->content)
          {{-- The outline's copy, not the stored one: it is the same document with an id on
               every heading, which is what "On this page" links to. Sanitized on the way IN,
               so escaping here would print the markup instead of the document. --}}
          <div class="wi-rich mt-4">{!! $outline['html'] !!}</div>
        @else
          <p class="text-[13px] text-faint mt-4">This page is empty.</p>
        @endif
      @else
        <h1 class="text-[30px] font-bold text-head tracking-tight">{{ $collection->name }}</h1>
        <p class="text-[14px] text-sub mt-3">
          There are no pages in this collection yet.
        </p>
      @endif

      <footer class="mt-16 pt-5 border-t border-line">
        <p class="text-[12px] text-faint">Published with Project Block</p>
      </footer>
      </div>
      </div>

      {{-- On this page. Sticky, and only when the document actually has headings — an empty
           column headed "On this page" is worse than no column. --}}
      @if ($showToc)
        <aside class="hidden xl:block w-56 shrink-0">
          <div class="sticky top-0">
            <p class="text-[11px] font-semibold text-faint uppercase tracking-wide">On this page</p>
            <ul class="mt-2 space-y-1 border-l border-line">
              @foreach ($outline['toc'] as $entry)
                <li>
                  <a href="#{{ $entry['id'] }}"
                     @class([
                       'block -ml-px border-l py-1 text-[12px] leading-snug border-transparent text-sub hover:text-ink hover:border-stroke',
                       'pl-3' => $entry['level'] === 1,
                       'pl-6' => $entry['level'] === 2,
                       'pl-9' => $entry['level'] === 3,
                       'pl-12' => $entry['level'] === 4,
                     ])>{{ $entry['text'] }}</a>
                </li>
              @endforeach
            </ul>
          </div>
        </aside>
      @endif
    </main>
  </div>
  <script>
    (function () {
      var nav = document.querySelector('[data-nav]');
      if (!nav) return;

      // Everything that opens and closes: the group headings, and every page with pages under
      // it. One mechanism, because they behave identically.
      var nodes = Array.prototype.slice.call(nav.querySelectorAll('[data-nav-node]'));
      // Only the group headings hide when a filter matches nothing in them.
      var sections = Array.prototype.slice.call(nav.querySelectorAll('[data-nav-section]'));

      /* ---- expand and collapse ----------------------------------------------------------
         Remembered per collection, like the Group view's sections: a tidied navigation that
         springs open again on the next page is not tidying, it is a setting that does not
         hold. localStorage rather than a cookie — this page is also served to strangers, and
         which headings somebody folded away is nobody's business but their browser's. */
      var storeKey = 'pb.wiki.readerNav.' + nav.getAttribute('data-nav');
      var collapsed = [];

      try { collapsed = JSON.parse(localStorage.getItem(storeKey) || '[]'); } catch (e) {}

      /* Found by KEY, never by walking the DOM: a page's chevron sits inside its row while a
         group's sits in its heading, and a `:scope >` lookup that suits one misses the other.
         The id and the aria-controls already name the pair, so use them. */
      function paint(node, open) {
        var key = node.getAttribute('data-nav-key');
        var body = nav.querySelector('#nav-' + key);
        var button = nav.querySelector('[aria-controls="nav-' + key + '"]');

        if (!body || !button) return;

        body.hidden = !open;
        button.setAttribute('aria-expanded', String(open));

        var chevron = button.querySelector('[data-nav-chevron]');
        if (chevron) chevron.classList.toggle('-rotate-90', !open);
      }

      function restore() {
        nodes.forEach(function (node) {
          // Whatever holds the page being read is never folded away: collapsing the navigation
          // must not hide where the reader is standing. Marked server-side, all the way up the
          // branch, so it is right on the first paint rather than after a script has run.
          var open = node.hasAttribute('data-nav-current')
            || collapsed.indexOf(node.getAttribute('data-nav-key')) === -1;

          paint(node, open);
        });
      }

      nodes.forEach(function (node) {
        var key = node.getAttribute('data-nav-key');
        var button = nav.querySelector('[aria-controls="nav-' + key + '"]');
        if (!button) return;

        button.addEventListener('click', function () {
          var at = collapsed.indexOf(key);

          if (at === -1) { collapsed.push(key); } else { collapsed.splice(at, 1); }

          try { localStorage.setItem(storeKey, JSON.stringify(collapsed)); } catch (e) {}

          paint(node, at !== -1);
        });
      });

      restore();

      /* ---- filter -----------------------------------------------------------------------
         Plain JS and no endpoint: everything it searches is already on the page, and a round
         trip to hide four links would be theatre. */
      var box = document.getElementById('wiki-nav-filter');
      var empty = document.getElementById('wiki-nav-empty');
      if (!box) return;

      box.addEventListener('input', function () {
        var q = box.value.trim().toLowerCase();
        var shown = 0;

        Array.prototype.forEach.call(nav.querySelectorAll('[data-nav-item]'), function (li) {
          var hit = !q || li.getAttribute('data-nav-item').indexOf(q) !== -1;
          li.hidden = !hit;
          if (hit) shown++;
        });

        /* A parent whose CHILD matched stays on screen. Deepest first, so a match three levels
           down has already un-hidden its own parent by the time that parent is considered —
           otherwise the branch leading to a hit is cut above it and the hit disappears with it. */
        nodes.slice().reverse().forEach(function (node) {
          var row = node.querySelector(':scope > [data-nav-item]');

          if (q && row && row.hidden && node.querySelector('[data-nav-item]:not([hidden])')) {
            row.hidden = false;
          }
        });

        sections.forEach(function (section) {
          // A group whose every page is filtered out is a heading with nothing under it.
          section.hidden = !!q && !section.querySelector('[data-nav-item]:not([hidden])');
        });

        // While filtering everything surviving is open: a match hidden inside a folded branch
        // is a search that found nothing, as far as the reader can tell.
        if (q) { nodes.forEach(function (node) { paint(node, true); }); } else { restore(); }

        if (empty) empty.classList.toggle('hidden', shown > 0);
      });
    })();
  </script>
</body>
</html>
