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
      {!! pb_icon('chevron-left', 18) !!}
    </a>
    <span class="flex items-center gap-2">
      <span class="h-6 w-6 rounded-md bg-slate-700 text-white grid place-items-center text-[11px] font-semibold">{{ $workspace->initial() }}</span>
      <span class="font-medium text-[13px] max-w-[150px] truncate">{{ $workspace->name }}</span>
      <span class="text-faint">/</span>
      <span class="text-[13px] text-sub truncate max-w-[220px]">{{ $project->name }}</span>
    </span>
    <div class="ml-auto flex items-center gap-1.5">
      <a href="{{ route('settings.general') }}" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Workspace settings">{!! pb_icon('gear-outline', 18) !!}</a>
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
              <span class="inline-flex items-center gap-1 text-[12px] text-sub border border-line rounded px-2 py-0.5">{!! pb_icon('lock', 12) !!}Private</span>
            @else
              <span class="inline-flex items-center gap-1 text-[12px] text-sub border border-line rounded px-2 py-0.5">{!! pb_icon('globe', 12) !!}Public</span>
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
