{{-- Everything the <wi-list> work item grid needs, in the one order that works.

     THE ORDER IS LOAD-BEARING. The skin's group rule and Tabulator's base theme collide at
     equal specificity:

         ours       .wi-grid .tabulator-group          (0,2,0)
         Tabulator  .tabulator-row.tabulator-group     (0,2,0)   background: #ccc

     At equal specificity the later stylesheet wins, so work-items.css must come AFTER
     tabulator.min.css. Loading them the other way round turns every collapsed group row grey
     — which is exactly what happened when the Cycles screen listed these tags for itself.

     Hence a partial rather than a copy per page: the list is one component, so its assets are
     one block, and a page that mounts <wi-list> cannot get the order wrong.

     Include AFTER assets/css/styles.css and assets/js/settings/app.js, and BEFORE the screen
     script that mounts the component — deferred scripts run in document order, so that leaves
     PB.boot and WiList both defined by the time the screen script runs. --}}

<link rel="stylesheet" href="{{ pb_asset('assets/vendor/tabulator/tabulator.min.css') }}" />
<link rel="stylesheet" href="{{ pb_asset('assets/css/tabulator-skin.css') }}" />
<link rel="stylesheet" href="{{ pb_asset('assets/css/work-items.css') }}" />
<script src="{{ pb_asset('assets/vendor/tabulator/tabulator.min.js') }}"></script>

{{-- The row vocabulary (state/priority icons, chips, avatars), the shared <wi-calendar>
     date picker, and the <wi-list> grid itself. --}}
<script defer src="{{ pb_asset('assets/js/projects/work-item-ui.js') }}"></script>
<script defer src="{{ pb_asset('assets/js/projects/date-picker.js') }}"></script>
<script defer src="{{ pb_asset('assets/js/projects/work-item-list.js') }}"></script>
