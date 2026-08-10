@extends('layouts.auth')
@section('title', 'You are all set — Project Block')

@section('body')
  <div class="h-1 w-full bg-line"><div class="h-full bg-brand" style="width:100%"></div></div>
  <main class="flex-1 flex justify-center items-center px-5">
    <div class="w-full max-w-[430px] py-12 text-center">
      <div class="mx-auto mb-5 h-14 w-14 rounded-full bg-brand grid place-items-center">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5 9-11" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <h1 class="text-[24px] font-bold text-head">You're all set.</h1>
      <p class="text-[15px] text-sub mt-2">Onboarding is complete. Workspace creation (Phase 3) picks up from here.</p>
      <form method="POST" action="{{ route('logout') }}" class="mt-8">
        @csrf
        <button type="submit" class="text-[13px] text-link font-semibold hover:underline">Log out</button>
      </form>
    </div>
  </main>
@endsection
