@extends('help-center.layout')

@section('title', $space->name.' — '.$settingLabel)

@push('scripts')
  @if ($settingKind === 'members')
    {{-- Members is the ONE page in here that is not the settings panel (P10). It is the same
         Tabulator grid Project Settings › Members is, so it brings the grid's own assets and
         its own script rather than being rebuilt as a panel — and space-settings.js is not
         loaded at all, because there is no settings root on this page for it to mount into.

         The order matters: the skin must follow tabulator's own stylesheet. --}}
    <link rel="stylesheet" href="{{ pb_asset('assets/vendor/tabulator/tabulator.min.css') }}" />
    <link rel="stylesheet" href="{{ pb_asset('assets/css/tabulator-skin.css') }}" />
    <script src="{{ pb_asset('assets/vendor/tabulator/tabulator.min.js') }}"></script>
    <script defer src="{{ pb_asset('assets/js/help-center/space-members.js') }}"></script>
  @elseif ($settingKind === 'sla')
    {{-- SLA is its own screen (docs/features/helpdesk-sla.md, §2), not a settings panel.

         Its own script rather than another `kind` branch inside space-settings.js: that file is
         already 3,500 lines of one component, and this page is four resources with four of their
         own endpoints. Adding it there would have meant one component that no longer fits in a
         head — the same reason Members brings its own. --}}
    <script defer src="{{ pb_asset('assets/js/help-center/sla-settings.js') }}"></script>
  @else
    {{-- The rich-text editor (P41), for the Email Template panel's bodies and signature
         content (P48). Loaded for every settings page rather than only that one: `@push` runs
         before the panel is known to the browser, and one conditional include keyed on a
         setting name is how the editor comes to be missing from the page that needed it. --}}
    @include('partials.rich-editor')

    {{-- BEFORE space-settings.js: the Workflow panel's editor uses the same status card the
         setup wizard's step 4 does (P16), and reads it at parse time. --}}
    <script defer src="{{ pb_asset('assets/js/help-center/status-card.js') }}"></script>
    <script defer src="{{ pb_asset('assets/js/help-center/space-settings.js') }}"></script>
  @endif
@endpush

@section('content')
  {{-- Space Settings (docs/features/help-center.md, P11).

       Three columns: the Help Center's own navigation on the far left (from the shared layout),
       this Space's Settings navigation next to it, and the selected page on the right — the
       shape Project Settings uses, and the classes are lifted from it rather than reinvented so
       the two screens cannot drift apart.

       It deliberately keeps the Space's toolbar and section tabs above all three. Project
       Settings is a full-screen detour out of the project, with its own document and its own
       way back; a Space's Settings is a SECTION of the Space, sitting beside Overview and Inbox.
       Dropping the tabs here would mean leaving the Space to configure
       it, and needing a "back" affordance to undo that. --}}

  {{-- Toolbar — the same h-12 bordered bar every other screen in the module carries. --}}
  {{-- `shrink-0` — see the note in help-center/space.blade.php (P81). This bar is a flex child
       of a scrolling column, and without it a tall page compresses `h-12` and pushes the buttons
       onto the border. --}}
  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line shrink-0">
    @include('partials.sidebar-expand')
    <a href="{{ route('help-center.spaces.show', $space->id) }}"
       class="flex items-center gap-2 text-[14px] font-medium text-ink min-w-0 hover:opacity-80">
      {!! pb_icon('rectangles-pair', 16, 'text-sub shrink-0') !!}
      <span class="truncate">{{ $space->name }}</span>
    </a>
    <span class="text-faint shrink-0">/</span>
    <span class="text-[13px] text-sub shrink-0">Settings</span>
    @if ($space->archived_at)
      <span class="_moretogether-badge _moretogether-badge--off shrink-0">Archived</span>
    @endif
    <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
      <a href="{{ route('help-center.spaces.index') }}"
         class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">
        All Spaces
      </a>
    </div>
  </div>

  {{-- The Space's sections, with Settings lit. Same bar as every other Space screen — the
       same partial, so it cannot be "the same" only until one of them is edited. --}}
  @include('partials.help-center-space-tabs')

  {{-- On a narrow screen the sidebar below is hidden, exactly as Project Settings hides its
       own. Hiding it outright would strand a phone inside whichever section it opened with no
       way to reach the others, so the same list is rendered as a scrolling chip row. --}}
  <div class="md:hidden border-b border-line overflow-x-auto shrink-0">
    <div class="flex items-center gap-1 px-5 py-2 w-max">
      @foreach ($settingsNav as $item)
        @if ($item['status'] === 'soon')
          <span class="inline-flex items-center gap-1.5 h-7 px-3 rounded-md text-[12px] text-faint whitespace-nowrap cursor-not-allowed">
            {{ $item['label'] }}
            <span class="text-[10px] bg-hover text-sub rounded px-1.5 py-0.5">Soon</span>
          </span>
        @else
          <a href="{{ $item['url'] }}"
             @class(['inline-flex items-center h-7 px-3 rounded-md text-[12px] border whitespace-nowrap', 'border-stroke bg-sel text-brand font-semibold' => $item['key'] === $setting, 'border-transparent text-ink hover:bg-hover' => $item['key'] !== $setting])>
            {{ $item['label'] }}
          </a>
        @endif
      @endforeach
    </div>
  </div>

  {{-- `flex-1` so this row takes the height left over below the toolbar and the tabs, which is
       what lets the sidebar's border run to the bottom of the window on a SHORT page (P55).
       `items-start` keeps the PAGE top-aligned; the aside opts out with `self-stretch`.

       `min-h-0` is deliberately NOT here any more (P76).

       It was, and it capped this row at the leftover VIEWPORT height: a flex item with
       `min-height: 0` may shrink below its own content, so on a long Settings page — Company &
       Customer with three cards, two field tables and a mapping table — the row stayed one
       screen tall while its content spilled past it. The aside stretched to the row, so the
       sidebar and its right-hand border stopped at the fold and left a white gap under it for
       the rest of the scroll.

       Without it the row's min-height is `auto`, meaning its content, and `flex-1` still grows
       it to fill a short page. Height becomes max(leftover, content) — which is both halves of
       what the sidebar needs, with no pixel value to go stale when a section is added. --}}
  <div class="flex items-start flex-1">
    {{-- The Settings sidebar. w-60, px-2, h-8 rows, `bg-sel text-brand font-medium` when
         active — the same treatment projects/settings/layout.blade.php gives its nav, because
         "which settings page am I on" should look the same wherever you are.

         TWO elements doing two jobs (P79), not one doing both badly:

         The `aside` still stretches to the full content height (P76), which is what draws the
         column and its right-hand border all the way down. It is the BACKGROUND.

         The `div` inside it is `sticky top-0` and is the MENU. Making the aside itself sticky
         would not work: it is as tall as the content (1629px on this page), and a sticky element
         taller than the scrollport has nowhere to stick — it just scrolls. So the tall thing
         stays put and the short thing sticks inside it.

         `top-0` is the top of the SCROLL CONTAINER, not of the window — the toolbar and the
         Space tabs live inside that container and scroll away above it, which is what makes the
         menu sit directly under the topbar once you have scrolled.

         `max-h-screen overflow-y-auto` is a safety net, not the normal case: thirteen 32px rows
         plus the header is about 480px against an 840px scrollport, so it never engages today.
         It engages the day somebody adds enough settings sections to outgrow the window, and
         without it those last few would be unreachable. --}}
    <aside class="w-60 shrink-0 border-r border-line bg-white hidden md:flex flex-col self-stretch">
      <div class="sticky top-0 max-h-screen overflow-y-auto">
      <div class="px-4 pt-4 pb-2">
        {{-- Name the Space being configured, so it is clear WHOSE settings these are when
             several Spaces are open in different tabs. --}}
        <div class="text-[13px] font-semibold text-head truncate" title="{{ $space->name }}">{{ $space->name }}</div>
        <div class="text-[11px] font-semibold text-faint uppercase tracking-wide mt-0.5">Space settings</div>
      </div>
      <nav class="px-2 pb-6">
        @foreach ($settingsNav as $item)
          @if ($item['status'] === 'soon')
            {{-- Not a link: a Coming Soon page has nothing to open, and a nav item that
                 navigates to a placeholder is a promise the screen then has to break. --}}
            <span class="flex items-center gap-2 px-2 h-8 rounded-md text-faint cursor-not-allowed select-none"
                  title="{{ $item['label'] }} is coming soon">
              <span class="truncate">{{ $item['label'] }}</span>
              <span class="ml-auto text-[10px] bg-hover text-sub rounded px-1.5 py-0.5 shrink-0">Soon</span>
            </span>
          @else
            <a href="{{ $item['url'] }}"
               @class(['flex items-center gap-2 px-2 h-8 rounded-md', 'bg-sel text-brand font-medium' => $item['key'] === $setting, 'text-ink hover:bg-hover' => $item['key'] !== $setting])
               @if ($item['key'] === $setting) aria-current="page" @endif>
              <span class="truncate">{{ $item['label'] }}</span>
            </a>
          @endif
        @endforeach
      </nav>
      </div>
    </aside>

    {{-- The page. Every section but Members is the settings panel; Members is its grid.

         `pb-[200px]` is scroll room under the last card (P76). Every panel ends its own content
         with `py-10`, which puts the final section 40px off the bottom of the window — close
         enough that a Save button at the end of a long form reads as clipped, and short enough
         that there is nowhere to scroll to confirm it is not.

         On the CONTAINER rather than inside each panel: there are ten panel kinds and the JS
         builds each one's padding separately, so ten copies of the same value is ten places for
         it to drift. It also grows the row, which is what the sidebar stretches to — so the
         padding and the full-height menu above stay one measurement rather than two that have
         to agree. --}}
    <main class="flex-1 min-w-0 pb-[200px]">
      @if ($settingKind === 'members')
        {{-- The SAME member listing as Project Settings › Members: same Tabulator grid and skin,
             same toolbar (People count · search · filter · Add Member), same Actions row menu,
             same dialogs. Managing who works a Space's Inbox and managing who works a project
             should not feel like two applications.

             Two columns differ, and only because a membership means something different here: a
             Space has no roles of its own, so Role shows the WORKSPACE role, and the column a
             project spends on "Project Role" is spent here on Department Groups.

             Full-width padding rather than the panels' max-w-[820px]: this is a grid with six
             columns, and a settings panel's reading measure would squeeze it. --}}
        <div class="px-5 sm:px-8 py-6">
          <div id="help-center-members" data-bootstrap="{{ json_encode($bootstrap) }}">
            <p id="help-center-members-loading" class="text-[13px] text-sub">Loading members&hellip;</p>
          </div>
        </div>

        {{-- The same watchdog the Inbox carries, for the same reason: if the screen script never
             runs — a 404 on the asset, a JS error, a mount point that does not resolve — nothing
             replaces the placeholder and the page sits on it forever, with no way to tell a slow
             network from a broken build.

             INLINE rather than in space-members.js, because the failures worth catching here
             include "space-members.js did not load", and a guard inside the file it is guarding
             cannot fire. --}}
        <script>
          setTimeout(function () {
            var stuck = document.getElementById('help-center-members-loading');
            if (!stuck) return;

            stuck.className = 'max-w-[560px] rounded-lg border border-danger/30 bg-danger/5 px-4 py-3 text-[13px] text-ink';
            stuck.innerHTML = '<span class="font-semibold">The member list could not be displayed.</span>' +
              '<span class="block mt-1 text-[12px] text-sub">The members were loaded, but the screen failed to start. ' +
              'Reload the page; if it keeps happening, the browser console will say why.</span>' +
              '<button type="button" onclick="window.location.reload()" ' +
              'class="inline-flex items-center h-8 px-3 mt-2 rounded-md border border-stroke bg-white text-[13px] font-semibold text-ink hover:bg-hover">Reload</button>';
          }, 8000);
        </script>
      @elseif ($settingKind === 'sla')
        {{-- The same mount-point contract as every other panel: an id, a bootstrap payload and a
             placeholder the app replaces, so a screen that fails to boot says so. --}}
        <div id="help-center-sla" data-bootstrap="{{ json_encode($bootstrap) }}">
          <div class="max-w-[980px] mx-auto px-5 sm:px-8 py-10 text-sub text-[13px]">Loading SLA…</div>
        </div>
      @else
        {{-- Same mount-point contract as Project Settings: an id, a data-bootstrap payload, and
             a "Loading…" placeholder the Vue app replaces — so a failure to boot shows something
             honest rather than a blank column. --}}
        <div id="help-center-space-settings" data-bootstrap="{{ json_encode($bootstrap) }}">
          <div class="max-w-[820px] mx-auto px-5 sm:px-8 py-10 text-sub text-[13px]">Loading {{ $settingLabel }}…</div>
        </div>
      @endif
    </main>
  </div>
@endsection
