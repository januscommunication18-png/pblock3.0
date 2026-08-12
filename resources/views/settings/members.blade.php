@extends('settings.layout')

@push('section-script')
  {{-- Tabulator (table listing) + Project Block skin (matches setting-member.html). --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/tabulator/tabulator.min.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tabulator-skin.css') }}" />
  <script src="{{ pb_asset('assets/vendor/tabulator/tabulator.min.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/members.js') }}"></script>
@endpush
