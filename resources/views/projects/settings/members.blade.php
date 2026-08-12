@extends('projects.settings.layout')

@push('section-script')
  {{-- Same data grid + skin as Workspace → Settings → Members, so the two member screens
       read identically. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/tabulator/tabulator.min.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tabulator-skin.css') }}" />
  <script src="{{ pb_asset('assets/vendor/tabulator/tabulator.min.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/members.js') }}"></script>
@endpush
