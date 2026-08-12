@extends('projects.settings.layout')

{{-- Views §4.2 is one master switch plus sub-settings, which is the shape the shared
     feature-switch screen already renders — the same component Cycle, Module, Epic and Pages
     use. The sub-settings declare `requires => views` in the catalog, so they appear under the
     master switch exactly as parallel cycles sits under Cycles. --}}
@push('section-script')
  <script defer src="{{ pb_asset('assets/js/projects/features.js') }}"></script>
@endpush
