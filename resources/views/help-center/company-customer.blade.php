@extends('help-center.layout')

@section('title', 'Company & Customer')

@push('scripts')
  <script defer src="{{ pb_asset('assets/js/help-center/company-customer.js') }}"></script>
@endpush

@section('content')
  {{-- The Company & Customer management area (docs/features/help-center.md, P75 §11).

       Vue rather than Blade, in the shape of the Spaces index: the rows are searchable and the
       two tabs are one dataset filtered two ways, so switching between them should not be a
       page load. --}}
  <div id="help-center-company-customer" data-bootstrap="{{ json_encode($bootstrap) }}">
    <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
  </div>
@endsection
