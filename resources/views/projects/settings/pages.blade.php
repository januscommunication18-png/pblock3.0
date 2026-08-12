@extends('projects.settings.layout')

{{-- Same screen component as the Cycle, Module and Epic sections: all four are one feature
     switch, and the bootstrap decides which switch and what the page is called. --}}
@push('section-script')
  <script defer src="{{ pb_asset('assets/js/projects/features.js') }}"></script>
@endpush
