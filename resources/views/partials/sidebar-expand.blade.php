{{-- Brings the collapsed sidebar back, sitting at the head of the page's own title row.

     Invisible until the sidebar is collapsed — see the `[data-sidebar-expand]` rules in
     assets/css/styles.css — so it costs nothing while the panel is open.

     The click is handled by a delegated listener in partials/app-sidebar, keyed on the
     `data-sidebar-expand` attribute rather than an id. That is what lets a Vue-rendered
     header (the projects toolbar) carry the same control as a Blade one, without the sidebar
     script having to know which screens exist or wait for Vue to mount. --}}
<button type="button" data-sidebar-expand title="Show sidebar" aria-label="Show sidebar"
        aria-controls="sidebar" aria-expanded="false"
        class="h-7 w-7 place-items-center rounded-md text-sub hover:bg-hover hover:text-ink shrink-0">
  {!! pb_icon('sidebar', 16) !!}
</button>
{{-- Separates the sidebar control from the page's own title. Ships with the button and is
     hidden by the same rule, so an expanded sidebar never leaves a stray rule at the start
     of the row. Decorative, so it is hidden from assistive tech. --}}
<span data-sidebar-divider aria-hidden="true" class="h-5 w-px bg-line shrink-0"></span>
