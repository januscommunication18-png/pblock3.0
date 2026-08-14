{{-- The rich-text editor every long-form field mounts: <pg-editor> (Jodit), with <wi-editor>
     (Quill) as the fallback for a checkout without the licensed package.

     One partial rather than the same six lines on each screen, because the pair has an order:
     Jodit must be parsed before page-editor.js defines <pg-editor>, and page-editor.js before
     the screen script that names it in `components`. Getting that wrong on one page is how
     the drafts screen first shipped with no editor at all.

     Include AFTER assets/js/icons.js (the toolbar borrows the app's own icons) and BEFORE the
     screen script. --}}

@if (file_exists(public_path('assets/vendor/jodit/jodit.fat.min.js')))
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/jodit/jodit.fat.min.css') }}" />
  <script src="{{ pb_asset('assets/vendor/jodit/jodit.fat.min.js') }}"></script>
@endif

{{-- Quill stays loaded either way: it is the fallback, and comments and status updates are
     still <wi-editor> — only the description moved. --}}
<link rel="stylesheet" href="{{ pb_asset('assets/vendor/quill/quill.snow.css') }}" />
<script src="{{ pb_asset('assets/vendor/quill/quill.js') }}"></script>

<script defer src="{{ pb_asset('assets/js/projects/page-editor.js') }}"></script>
