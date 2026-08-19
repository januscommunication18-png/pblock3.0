@extends('help-center.layout')

@section('title', 'Inboxes')

@push('scripts')
  <script defer src="{{ pb_asset('assets/js/help-center/inboxes.js') }}"></script>
@endpush

@section('content')
  {{-- Vue mounts here (CLAUDE.md §14). The list is interactive — addresses are added and
       removed without a page load, and the inbound address is copied — so this one screen needs
       a component where Overview and the Space views do not. --}}
  <div id="help-center-inboxes" data-bootstrap="{{ json_encode([
      'inboxes' => $inboxes,
      'statuses' => config('help-center.address_statuses'),
      'endpointTemplates' => [
          'addresses' => route('help-center.addresses.store', ['inbox' => '__ID__']),
          'address' => route('help-center.addresses.destroy', ['inbox' => '__ID__', 'address' => '__ADDRESS__']),
      ],
  ]) }}">
    <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
  </div>
@endsection
