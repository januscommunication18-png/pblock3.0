@extends('help-center.layout')

@section('title', 'Spaces')

@push('scripts')
  <script defer src="{{ pb_asset('assets/js/help-center/spaces.js') }}"></script>
@endpush

@section('content')
  {{-- The Help Center's management screen (P3 §20), in the shape of the Projects index.

       Vue rather than Blade, because the rows are interactive: archiving, restoring and
       deleting all happen without a page load, and each one changes the card in place. --}}
  <div id="help-center-spaces" data-bootstrap="{{ json_encode($bootstrap) }}">
    <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
  </div>
@endsection
