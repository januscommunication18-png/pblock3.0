{{-- One page in the reader's navigation, and everything nested under it
     (docs/features/wiki.md).

     Recursive by @include: a page can nest to any depth, so the markup has to be able to draw
     itself — the same reason the collection screen's row component is recursive by name.

     Expects: $node (from WikiReader::tree()), $current, $pageUrl. --}}
@php
  $page = $node['page'];
  $children = $node['children'];
  $key = 'p'.$page->id;
  $isCurrent = $current && $current->id === $page->id;

  /* Does the page being read live anywhere below this one? Then this node opens whatever was
     collapsed last time — a reader must never have to go looking for where they already are. */
  $holdsCurrent = false;

  if ($current && $children) {
      $walk = function (array $nodes) use (&$walk, $current): bool {
          foreach ($nodes as $child) {
              if ($child['page']->id === $current->id || $walk($child['children'])) {
                  return true;
              }
          }

          return false;
      };

      $holdsCurrent = $walk($children);
  }
@endphp

<li @if ($children) data-nav-node data-nav-key="{{ $key }}" @if ($holdsCurrent) data-nav-current @endif @endif>
  {{-- The row is what the filter hides, NOT the <li> — hiding the item would take its matching
       children down with it. --}}
  <div data-nav-item="{{ strtolower($page->title) }}" class="flex items-center">
    @if ($children)
      <button type="button" data-nav-toggle aria-expanded="true" aria-controls="nav-{{ $key }}"
              aria-label="Toggle pages under {{ $page->title }}"
              class="h-7 w-5 shrink-0 grid place-items-center rounded text-faint hover:text-sub">
        <span data-nav-chevron class="transition-transform duration-150">{!! pb_icon('chevron-down', 11) !!}</span>
      </button>
    @else
      {{-- A spacer, so a leaf's title lines up with its siblings' rather than sitting where
           their chevron is. --}}
      <span class="w-5 shrink-0" aria-hidden="true"></span>
    @endif

    <a href="{{ $pageUrl($page) }}"
       @class([
         'block flex-1 min-w-0 px-2 py-1.5 rounded-md text-[13px] truncate',
         'bg-sel text-brand font-medium' => $isCurrent,
         'text-ink hover:bg-hover' => ! $isCurrent,
       ])>{{ $page->title }}</a>
  </div>

  @if ($children)
    <ul id="nav-{{ $key }}" data-nav-body class="mt-0.5 space-y-0.5 ml-2.5 pl-2 border-l border-line">
      @foreach ($children as $child)
        @include('partials.wiki-reader-nav-item', ['node' => $child])
      @endforeach
    </ul>
  @endif
</li>
