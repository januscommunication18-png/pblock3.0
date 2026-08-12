@extends('layouts.auth')
@section('title', 'Welcome — Project Block')

{{--
  Membership activated (invite spec §49/§50). The confirmation is shown briefly and then the
  browser continues into the workspace on its own; the CTA is there for anyone who would
  rather not wait, and for when meta refresh is disabled.
--}}
@push('head')
  <meta http-equiv="refresh" content="4;url={{ route('welcome') }}" />
@endpush

@section('body')
  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[420px] pt-16 sm:pt-24 text-center">
      <span class="mx-auto mb-5 h-12 w-12 rounded-full bg-brand/10 grid place-items-center" aria-hidden="true">
        {!! pb_icon('check-large', 24, 'text-brand') !!}
      </span>

      <h1 class="text-[24px] font-bold text-head leading-tight">Welcome to {{ $workspace->name }}</h1>
      <p class="mt-2 text-[15px] text-sub">
        Your Project Block account is ready and you've joined the workspace.
      </p>

      <a href="{{ route('welcome') }}" class="mt-7 inline-flex items-center justify-center h-11 px-6 rounded-lg bg-brand hover:bg-brand-dark text-white text-[14px] font-semibold transition-colors">
        Go to workspace
      </a>
    </div>
  </main>
@endsection
