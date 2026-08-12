@extends('projects.settings.layout')

{{-- Estimation has its own screen rather than the shared toggle list: §5 makes enabling
     inseparable from choosing a system, and §10 puts the value editor on the same page. --}}
@push('section-script')
  <script defer src="{{ pb_asset('assets/js/projects/estimation.js') }}"></script>
@endpush
