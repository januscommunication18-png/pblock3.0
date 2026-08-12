{{-- The work item ROW VOCABULARY: state and priority icons, chips, avatars, label pills, the
     shared <wi-calendar> date picker, and the styles they render with.

     Split out of work-item-assets so a screen can reuse the chips WITHOUT taking Tabulator
     with them. The Views grid is the reason: it renders the same status, priority, assignee,
     label, epic, cycle and estimate chips from these same helpers — a chip has to mean the
     same thing wherever it appears — but it runs on RevoGrid, so shipping the list's grid
     engine alongside it would be dead weight on every page load.

     work-items.css carries the chip styles as well as the .wi-grid skin; the two are in one
     file and splitting them is not worth a second stylesheet. --}}

<link rel="stylesheet" href="{{ pb_asset('assets/css/work-items.css') }}" />
<script defer src="{{ pb_asset('assets/js/projects/work-item-ui.js') }}"></script>
<script defer src="{{ pb_asset('assets/js/projects/date-picker.js') }}"></script>
