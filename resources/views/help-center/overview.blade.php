@extends('help-center.layout')

@section('title', 'Overview')

@section('content')
  {{-- The module's one heading style: the h-12 bordered toolbar the Spaces listing and the
       Projects index both use. --}}
  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">
    @include('partials.sidebar-expand')
    <span class="flex items-center gap-2 text-[14px] font-medium text-ink">
      {!! pb_icon('house', 16, 'text-sub') !!}
      Overview
    </span>
  </div>

  <div class="px-5 sm:px-8 py-6">
    <p class="text-[13px] text-sub">How this workspace's Help Center is set up.</p>

    {{-- Configuration counts only. §14's operational figures — open conversations, response
         times, team workload — are listed there as future examples and measure things that do
         not exist yet; a zero beside "Response time" would read as a measurement rather than as
         a feature that has not shipped. --}}
    <div class="mt-6 grid gap-3 sm:grid-cols-3 max-w-[720px]">
      @foreach ($stats as $stat)
        <div class="rounded-lg border border-line px-4 py-3">
          <div class="flex items-center gap-2 text-sub">
            {!! pb_icon($stat['icon'], 14) !!}
            <span class="text-[12px]">{{ $stat['label'] }}</span>
          </div>
          <div class="mt-1 text-[22px] font-semibold text-head">{{ $stat['value'] }}</div>
        </div>
      @endforeach
    </div>

    <div class="mt-8 max-w-[720px] rounded-lg border border-line bg-[#f9fafb] px-4 py-4">
      <h2 class="text-[13px] font-semibold text-head">Conversations are next</h2>
      <p class="mt-1 text-[13px] text-sub leading-relaxed">
        Your Spaces and Inboxes are ready and every Inbox has its own inbound address. Receiving
        and replying to customer email arrives in the next release — until then the conversation
        views are empty.
      </p>
      <a href="{{ route('help-center.spaces.index') }}"
         class="inline-flex items-center h-8 px-3 mt-3 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover bg-white">
        Manage your Spaces
      </a>
    </div>
  </div>
@endsection
