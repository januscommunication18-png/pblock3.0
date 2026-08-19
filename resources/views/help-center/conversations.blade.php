@extends('help-center.layout')

@section('title', 'Conversations')

@section('content')
  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">
    @include('partials.sidebar-expand')
    <span class="flex items-center gap-2 text-[14px] font-medium text-ink">
      {!! pb_icon('inbox', 16, 'text-sub') !!}
      Conversations
    </span>
  </div>

  <div class="px-5 sm:px-8 py-6">
    <p class="text-[13px] text-sub">Every conversation you have access to, across the Help Center.</p>

    {{-- §14's filters. Rendered from the same config as a Space's system views, so the two
         lists cannot drift apart — they name the same six things. Inert while there is nothing
         to filter, and marked so: a control that looks live and does nothing is worse than one
         that says it is waiting. --}}
    <div class="mt-5 flex flex-wrap items-center gap-1" aria-disabled="true">
      @foreach ($filters as $i => $filter)
        <span @class(['inline-flex items-center h-7 px-3 rounded-md text-[12px] border', 'border-stroke bg-sel text-brand font-semibold' => $i === 0, 'border-line text-faint' => $i !== 0])>
          {{ $filter['label'] }}
        </span>
      @endforeach
    </div>

    <div class="mt-6 rounded-lg border border-line px-6 py-14 text-center max-w-[720px]">
      <div class="mx-auto h-10 w-10 rounded-lg bg-hover grid place-items-center text-sub">
        {!! pb_icon('inbox', 18) !!}
      </div>
      <h2 class="mt-3 text-[14px] font-semibold text-head">No conversations yet</h2>
      <p class="mt-1 text-[13px] text-sub leading-relaxed max-w-[420px] mx-auto">
        Once your forwarding is live, customer email arriving at your connected addresses will
        appear here.
      </p>
    </div>
  </div>
@endsection
