@extends('help-center.layout')

@section('title', $title)

@push('scripts')
  <script defer src="{{ pb_asset('assets/js/help-center/company-customer-profile.js') }}"></script>
@endpush

@section('content')
  {{-- A Customer or a Company profile (docs/features/help-center.md, P75 §12–§13).

       ONE screen for both. The two differ in four field labels and in whether there is a
       Customers list beside the Tickets list — everything else (the header, the editable
       details, the custom fields grouped by Space, the ticket table) is the same page, and two
       templates would be two places to fix the next thing either of them gets wrong. --}}
  <div id="help-center-cc-profile" data-bootstrap="{{ json_encode($bootstrap) }}">
    <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
  </div>
@endsection
