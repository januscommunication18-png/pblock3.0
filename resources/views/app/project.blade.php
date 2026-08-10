@extends('layouts.app')
@section('title', $project->name.' — Project Block')

@php
  $gradients = config('projects.cover_gradients');
  $gradient = $gradients[abs(crc32($project->identifier)) % count($gradients)];
@endphp

@section('body')
  <form method="POST" action="{{ route('logout') }}" id="logout-form" class="hidden">@csrf</form>

  <!-- Topbar -->
  <header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
    <a href="{{ route('projects.index') }}" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Back to Projects">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <span class="flex items-center gap-2">
      <span class="h-6 w-6 rounded-md bg-slate-700 text-white grid place-items-center text-[11px] font-semibold">{{ $workspace->initial() }}</span>
      <span class="font-medium text-[13px] max-w-[150px] truncate">{{ $workspace->name }}</span>
      <span class="text-faint">/</span>
      <span class="text-[13px] text-sub truncate max-w-[220px]">{{ $project->name }}</span>
    </span>
    <div class="ml-auto flex items-center gap-1.5">
      <a href="{{ route('settings.general') }}" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Workspace settings"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9c.2.62.78 1.04 1.43 1.05H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
      <span class="h-7 w-7 rounded-full bg-emerald-500 grid place-items-center text-white text-[11px] font-bold">{{ $user->initial() }}</span>
    </div>
  </header>

  <main class="flex-1 min-w-0 overflow-y-auto">
    {{-- Cover --}}
    <div class="h-40 sm:h-52 relative"
         @if ($project->cover_url) style="background-image:url('{{ $project->cover_url }}');background-size:cover;background-position:center"
         @else style="background:{{ $gradient }}" @endif>
    </div>

    <div class="max-w-[900px] mx-auto px-5 sm:px-8 -mt-8">
      <div class="flex items-end gap-4">
        <span class="h-16 w-16 rounded-2xl bg-white shadow-md ring-1 ring-black/5 grid place-items-center text-[24px] font-bold text-ink">{{ $project->initial() }}</span>
        <div class="pb-1">
          <div class="flex items-center gap-2 flex-wrap">
            <h1 class="text-[22px] font-bold text-head">{{ $project->name }}</h1>
            @if ($project->visibility === 'private')
              <span class="inline-flex items-center gap-1 text-[12px] text-sub border border-line rounded px-2 py-0.5"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 11V8a4 4 0 018 0v3" stroke="currentColor" stroke-width="1.7"/></svg>Private</span>
            @else
              <span class="inline-flex items-center gap-1 text-[12px] text-sub border border-line rounded px-2 py-0.5"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18" stroke="currentColor" stroke-width="1.6"/></svg>Public</span>
            @endif
          </div>
          <div class="text-[13px] text-sub mt-0.5">{{ $project->identifier }}{{ $project->lead ? ' · Lead: '.$project->lead->displayName() : '' }}</div>
        </div>
      </div>

      @if ($project->description)
        <p class="text-[14px] text-ink mt-5 whitespace-pre-line">{{ $project->description }}</p>
      @endif

      <div class="mt-8 border border-line rounded-xl p-6">
        <h2 class="text-[15px] font-semibold text-head">Project created 🎉</h2>
        <p class="text-[13px] text-sub mt-1 max-w-[560px]">This is <span class="font-medium text-ink">{{ $project->name }}</span>. Work items, cycles, modules and pages arrive in the next phases of Project Block. For now you can manage the project from workspace settings.</p>
        <div class="flex flex-wrap gap-3 mt-4">
          <a href="{{ route('projects.index') }}" class="h-9 px-4 grid place-items-center rounded-md border border-stroke hover:bg-hover text-ink text-[13px] font-semibold">Back to Projects</a>
          <a href="{{ route('settings.general') }}" class="h-9 px-4 grid place-items-center rounded-md border border-stroke hover:bg-hover text-ink text-[13px] font-semibold">Workspace settings</a>
        </div>
      </div>
      <div class="h-10"></div>
    </div>
  </main>

  <script>
    document.getElementById('logout-form'); // present for parity with app shell
  </script>
@endsection
