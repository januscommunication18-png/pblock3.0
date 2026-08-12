@extends('projects.settings.layout')

{{-- Same screen component as the Cycle section: both are lists of feature switches, and the
     bootstrap decides which switches and what the page is called. --}}
@push('section-script')
  <script defer src="{{ pb_asset('assets/js/projects/features.js') }}"></script>
@endpush
