@extends('layouts.auth')
@section('title', 'Invitation unavailable — Project Block')

{{--
  Every "this link cannot be used" state (invite spec §62–§64, §69/§70). An unknown token
  shows the same panel as a revoked one, so the page cannot be used to probe which workspaces
  or invitations exist (§73).
--}}
@php
  $panels = [
      'expired' => [
          'heading' => 'Invitation expired',
          'body' => 'This invitation is no longer valid. Please contact the workspace administrator for a new invitation.',
      ],
      'revoked' => [
          'heading' => 'Invitation no longer available',
          'body' => 'This workspace invitation has been cancelled.',
      ],
      'unknown' => [
          'heading' => 'Invitation no longer available',
          'body' => 'This workspace invitation has been cancelled.',
      ],
      'accepted' => [
          'heading' => 'This invitation has already been accepted',
          'body' => 'Sign in with the invited email address to open the workspace.',
      ],
      'workspace_unavailable' => [
          'heading' => 'This workspace invitation is no longer valid',
          'body' => 'The workspace is unavailable. Please contact the workspace administrator.',
      ],
  ];
  $panel = $panels[$state] ?? $panels['unknown'];
@endphp

@section('body')
  <header class="flex items-center justify-between px-5 sm:px-10 py-6">
    <span class="flex items-center gap-2">
      <svg width="26" height="26" viewBox="0 0 32 32" fill="#0f0f10" aria-hidden="true">
        <path d="M5 21 L15 4 L20.5 4 L10.5 21 Z" /><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z" />
      </svg>
      <span class="text-[20px] font-bold tracking-tight text-head">Project Block</span>
    </span>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[420px] pt-10 sm:pt-16 text-center">
      <span class="mx-auto mb-5 h-12 w-12 rounded-full bg-[#f3f4f6] grid place-items-center" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="text-faint">
          <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
          <path d="M12 7v6M12 16.5v.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
      </span>
      <h1 class="text-[22px] font-bold text-head leading-tight">{{ $panel['heading'] }}</h1>
      <p class="mt-2 text-[15px] text-sub">{{ $panel['body'] }}</p>

      <a href="{{ route('signin') }}" class="mt-7 inline-flex items-center justify-center h-11 px-6 rounded-lg border border-line text-[14px] font-semibold text-ink hover:bg-hover transition-colors">
        Go to sign in
      </a>
    </div>
  </main>
@endsection
