@extends('layouts.app')
@section('title', 'Projects — Project Block')

@section('body')
  {{-- Hidden sign-out form (POST /logout) --}}
  <form method="POST" action="{{ route('logout') }}" id="logout-form" class="hidden">@csrf</form>

  <!-- ============ AppTopbar ============ -->
  <header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
    <button id="open-sidebar" class="lg:hidden h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>

    <div class="flex items-center gap-2 shrink-0">
      <span class="flex items-center gap-2 px-2 h-9">
        <span class="h-6 w-6 rounded-md bg-slate-700 text-white grid place-items-center text-[11px] font-semibold">{{ $workspace->initial() }}</span>
        <span class="font-medium text-[13px] max-w-[110px] sm:max-w-[150px] truncate">{{ $workspace->name }}</span>
      </span>
      <button id="switch-ws-btn" class="inline-flex items-center h-7 px-2.5 rounded-md border border-brand text-[12px] text-brand hover:bg-hover whitespace-nowrap">Switch workspace</button>
    </div>

    <div class="flex-1 flex justify-center px-2">
      <div class="relative w-full max-w-md">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="absolute left-3 top-1/2 -translate-y-1/2 text-faint"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <input type="text" placeholder="Search" class="w-full h-9 rounded-md bg-hover pl-9 pr-3 text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />
      </div>
    </div>

    <div class="flex items-center gap-1.5 shrink-0">
      <a href="{{ route('welcome') }}" class="hidden sm:inline-flex items-center h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">Get started</a>
      <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Help"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M9.5 9.5a2.5 2.5 0 114 2c-1 .7-1.5 1.2-1.5 2.5M12 17.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
      <div class="relative ml-1">
        <button id="user-btn" class="h-7 w-7 rounded-full bg-emerald-500 grid place-items-center text-white text-[11px] font-bold ring-2 ring-transparent focus:outline-none">{{ $user->initial() }}</button>
        <div id="user-menu" class="hidden absolute right-0 top-full mt-2 w-64 bg-white border border-line rounded-xl shadow-lg z-50 p-1.5">
          <div class="relative rounded-lg overflow-hidden px-4 pt-7 pb-4 text-center" style="background-color:#9ca3af;">
            <span class="h-14 w-14 rounded-full bg-brand text-white grid place-items-center text-[20px] font-semibold mx-auto shadow-sm">{{ $user->initial() }}</span>
            <div class="text-[14px] font-semibold text-white mt-2.5 drop-shadow-sm">{{ $user->displayName() }}</div>
            <div class="text-[12px] text-white/90 drop-shadow-sm">{{ $user->email }}</div>
          </div>
          <div class="pt-1.5">
            <a href="{{ route('workspaces.create') }}" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              Create workspace
            </a>
            <a href="{{ route('settings.general') }}" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><circle cx="12" cy="12" r="2.4" stroke="currentColor" stroke-width="1.5"/><path d="M19 12a7 7 0 00-.1-1l2-1.5-2-3.4-2.3 1a7 7 0 00-1.7-1l-.3-2.6h-4l-.3 2.6a7 7 0 00-1.7 1l-2.3-1-2 3.4L4 11a7 7 0 000 2l-2 1.5 2 3.4 2.3-1a7 7 0 001.7 1l.3 2.6h4l.3-2.6a7 7 0 001.7-1l2.3 1 2-3.4-2-1.5a7 7 0 00.1-1z" stroke="currentColor" stroke-width="1.4"/></svg>
              Workspace settings
            </a>
            <button type="submit" form="logout-form" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M9 21H6a2 2 0 01-2-2V5a2 2 0 012-2h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Sign out
            </button>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="flex-1 flex min-h-0 relative">
    <!-- AppRail -->
    <nav class="hidden lg:flex w-16 shrink-0 border-r border-line bg-[#f6f7f8] flex-col items-center py-3 gap-1">
      <a href="{{ route('projects.index') }}" class="flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg bg-sel text-brand">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/></svg>
        <span class="text-[10px] text-center leading-tight">Projects</span>
      </a>
      <a href="{{ route('settings.general') }}" title="Workspace settings"
         class="mt-auto flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg text-sub hover:bg-hover hover:text-ink">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9c.2.62.78 1.04 1.43 1.05H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="text-[10px] text-center leading-tight">Settings</span>
      </a>
    </nav>

    <div id="sidebar-backdrop" class="hidden lg:hidden fixed inset-0 bg-black/30 z-30"></div>

    <!-- AppSidebar -->
    <aside id="sidebar" class="w-60 shrink-0 border-r border-line bg-white flex flex-col fixed lg:relative inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-200">
      <div class="flex items-center gap-2 px-4 h-12 shrink-0">
        <span class="font-semibold text-ink">Projects</span>
        <button id="close-sidebar" class="lg:hidden ml-auto h-7 w-7 grid place-items-center rounded hover:bg-hover"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
      </div>
      <div class="px-2 overflow-y-auto flex-1">
        <a href="{{ route('welcome') }}" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M4 11l8-6 8 6v8a1 1 0 01-1 1h-4v-6H9v6H5a1 1 0 01-1-1v-8z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>Home</a>
        <a href="{{ route('projects.index') }}" class="flex items-center gap-2 px-2 h-8 rounded-md bg-sel text-brand font-medium"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>Projects</a>
        <div class="flex items-center gap-1 px-2 h-8 mt-3 text-[11px] font-semibold text-faint uppercase tracking-wide">Workspace</div>
        <span class="flex items-center gap-2 px-2 h-8 rounded-md text-ink font-medium truncate">🥭 {{ $workspace->name }}</span>
      </div>
    </aside>

    <!-- MAIN -->
    <main class="flex-1 min-w-0 overflow-y-auto">
      {{-- Projects header (PRJ-010/011) --}}
      <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line">
        <span class="inline-flex items-center gap-2 text-[14px] font-semibold text-head">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>
          Projects
        </span>
        <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
          <button class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap" disabled title="Sorting arrives with Project Administration"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M4 7h16M7 12h10M10 17h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>Created date</button>
          <button class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap" disabled title="Filters arrive with Project Administration"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M4 5h16l-6 8v5l-4 2v-7L4 5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>Filters</button>
          @if ($canCreate)
            <button id="add-project-btn" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold whitespace-nowrap"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Add Project</button>
          @endif
        </div>
      </div>

      <div class="px-5 sm:px-8 py-6">
        <div id="projects-grid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"></div>
        <div id="projects-empty" class="hidden border border-dashed border-stroke rounded-xl py-16 text-center">
          <div class="mx-auto h-12 w-12 rounded-xl bg-hover grid place-items-center text-sub mb-3"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg></div>
          <div class="text-[15px] font-semibold text-head">No projects yet</div>
          <p class="text-[13px] text-sub mt-1">Create your first project to start organizing work in this workspace.</p>
          @if ($canCreate)
            <button id="add-project-empty" class="mt-4 inline-flex items-center gap-1.5 h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Add Project</button>
          @endif
        </div>
      </div>
    </main>
  </div>

  @if ($canCreate)
  <!-- ============ AddProjectModal (PRJ-020) ============ -->
  <div id="add-project-modal" class="hidden fixed inset-0 z-[70] flex items-start justify-center p-4 sm:pt-24">
    <div class="absolute inset-0 bg-black/40" data-proj-close></div>
    <div class="relative w-full max-w-[860px] bg-white rounded-xl shadow-xl flex flex-col max-h-[88vh]">
      <!-- Cover (PRJ-027) -->
      <div id="proj-cover" class="relative h-32 rounded-t-xl bg-center bg-cover shrink-0" style="background:linear-gradient(120deg,#f6d5b8 0%,#eaa987 55%,#d98a68 100%)">
        <button data-proj-close class="absolute top-3 right-3 h-8 w-8 grid place-items-center rounded-md bg-white/85 text-sub hover:bg-white shadow-sm" title="Close"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
        <button id="proj-change-cover" type="button" class="absolute bottom-3 right-3 h-8 px-3 rounded-md bg-white/90 text-[12px] font-medium text-ink hover:bg-white shadow-sm">Change cover</button>
        <input id="proj-cover-input" type="file" accept="image/*" class="hidden" />
        <div id="proj-cover-progress" class="hidden absolute inset-x-3 bottom-3 bg-white/95 rounded-md px-3 py-2 shadow">
          <div class="flex items-center justify-between mb-1">
            <span id="proj-cover-name" class="text-[12px] text-sub truncate max-w-[70%]">Selected</span>
            <button type="button" id="proj-cover-clear" class="text-[12px] text-link">Remove</button>
          </div>
        </div>
      </div>

      <!-- Body -->
      <div class="px-5 sm:px-6 pt-5 pb-1 overflow-visible">
        <div class="flex flex-col sm:flex-row gap-3">
          <div class="flex-1">
            <input id="proj-name" type="text" placeholder="Project name" class="pb-input w-full" maxlength="{{ $config['nameMax'] }}" />
            <p data-err="name" class="hidden text-[12px] text-red-600 mt-1"></p>
          </div>
          <div class="relative sm:w-44">
            <input id="proj-id" type="text" placeholder="Project ID" class="pb-input pr-9 uppercase w-full" maxlength="{{ $config['identifierMax'] }}" />
            <span class="absolute right-2.5 top-[18px] -translate-y-1/2 text-faint" title="Project identifier — used to prefix work item IDs"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 11v5M12 8v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
            <p data-err="identifier" class="hidden text-[12px] text-red-600 mt-1"></p>
          </div>
        </div>

        <textarea id="proj-desc" rows="3" placeholder="Description" class="pb-textarea mt-3 w-full" maxlength="{{ $config['descriptionMax'] }}"></textarea>

        <div class="flex flex-wrap items-center gap-2 mt-3">
          <!-- Access (PRJ-025) -->
          <div class="relative">
            <button id="proj-access-btn" type="button" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">
              <span id="proj-access-icon" class="grid place-items-center"></span>
              <span id="proj-access-label">Public</span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="text-faint"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div id="proj-access-menu" class="pb-combo-list hidden absolute left-0 top-full mt-1 w-72 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-50"></div>
          </div>

          <!-- Lead (PRJ-026) -->
          <div class="relative">
            <button id="proj-lead-btn" type="button" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover">
              <span id="proj-lead-icon" class="grid place-items-center"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>
              <span id="proj-lead-label">Lead</span>
            </button>
            <div id="proj-lead-menu" class="pb-combo-list hidden absolute left-0 top-full mt-1 w-72 rounded-md bg-white p-2 shadow-lg outline outline-1 outline-black/5 z-50">
              <div class="relative mb-1">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input id="proj-lead-search" type="text" placeholder="Search members..." class="w-full h-9 pl-8 pr-3 rounded-md bg-hover text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />
              </div>
              <div id="proj-lead-list" class="max-h-48 overflow-y-auto"></div>
            </div>
          </div>
        </div>

        <p data-err="form" class="hidden text-[13px] text-red-600 mt-3"></p>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-end gap-2 px-5 sm:px-6 py-4 border-t border-line shrink-0">
        <button data-proj-close class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Cancel</button>
        <button id="proj-create-btn" class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-60 disabled:cursor-not-allowed">Create project</button>
      </div>
    </div>
  </div>
  @endif

  <!-- WorkspaceListModal (switcher) -->
  <div id="ws-modal" class="hidden fixed inset-0 z-[60] flex items-start justify-center p-4 sm:pt-24">
    <div class="absolute inset-0 bg-black/40" data-ws-close></div>
    <div class="relative w-full max-w-[560px] bg-white rounded-xl shadow-xl flex flex-col max-h-[80vh]">
      <div class="flex items-center justify-between px-6 py-4 border-b border-line shrink-0">
        <div>
          <h2 class="text-[16px] font-semibold text-head">Your workspaces</h2>
          <p class="text-[13px] text-sub mt-0.5">Switch, manage settings, or invite teammates.</p>
        </div>
        <button data-ws-close class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Close">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
      </div>
      <div id="ws-modal-list" class="overflow-y-auto px-4 py-3 space-y-2" data-workspaces='@json($workspaces)'></div>
      <div class="px-6 py-4 border-t border-line shrink-0">
        <a href="{{ route('workspaces.create') }}" class="w-full inline-flex items-center justify-center gap-2 h-9 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Create workspace
        </a>
      </div>
    </div>
  </div>

  @if (session('status'))
    <div class="fixed top-4 right-4 z-[80] w-full max-w-sm flex justify-end pointer-events-none">
      <div id="toast" class="pointer-events-auto w-full max-w-sm overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-black/5">
        <div class="p-4"><div class="flex items-start">
          <div class="shrink-0"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" fill="#22c55e"/><path d="M8 12l2.5 2.5L16 9" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
          <div class="ml-3 w-0 flex-1 pt-0.5"><p class="text-[13px] font-semibold text-head">Success</p><p class="mt-1 text-[13px] text-sub">{{ session('status') }}</p></div>
          <div class="ml-4 flex shrink-0"><button type="button" onclick="document.getElementById('toast').remove()" class="inline-flex rounded-md text-faint hover:text-sub"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></div>
        </div></div>
      </div>
    </div>
  @endif

  {{-- Bootstrap payload for the vanilla-JS controllers below. --}}
  <script id="pb-projects-data"
          type="application/json"
          data-projects='@json($projects)'
          data-members='@json($members)'
          data-config='@json($config)'
          data-endpoints='@json($endpoints)'></script>

  <script>
    (function () {
      var CSRF = document.querySelector('meta[name=csrf-token]').getAttribute('content');
      var boot = document.getElementById('pb-projects-data');
      var PROJECTS = JSON.parse(boot.getAttribute('data-projects') || '[]');
      var MEMBERS = JSON.parse(boot.getAttribute('data-members') || '[]');
      var CFG = JSON.parse(boot.getAttribute('data-config') || '{}');
      var EP = JSON.parse(boot.getAttribute('data-endpoints') || '{}');
      function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

      /* ---------- Mobile sidebar drawer ---------- */
      var sb = document.getElementById('sidebar'), bd = document.getElementById('sidebar-backdrop');
      document.getElementById('open-sidebar').addEventListener('click', function () { sb.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); });
      document.getElementById('close-sidebar').addEventListener('click', function () { sb.classList.add('-translate-x-full'); bd.classList.add('hidden'); });
      bd.addEventListener('click', function () { sb.classList.add('-translate-x-full'); bd.classList.add('hidden'); });

      /* ---------- User menu ---------- */
      var userBtn = document.getElementById('user-btn'), userMenu = document.getElementById('user-menu');
      userBtn.addEventListener('click', function (e) { e.stopPropagation(); userMenu.classList.toggle('hidden'); });
      document.addEventListener('click', function (e) { if (!userMenu.classList.contains('hidden') && !userMenu.contains(e.target) && !userBtn.contains(e.target)) userMenu.classList.add('hidden'); });

      /* ---------- Workspace switcher modal ---------- */
      var WS_COLORS = ['#334155', '#1b5f8a', '#7c3aed', '#0891b2', '#be123c', '#15803d', '#b45309', '#4338ca'];
      var wsModal = document.getElementById('ws-modal'), wsModalList = document.getElementById('ws-modal-list');
      var WORKSPACES = JSON.parse(wsModalList.getAttribute('data-workspaces') || '[]');
      wsModalList.innerHTML = WORKSPACES.map(function (w, i) {
        var color = WS_COLORS[i % WS_COLORS.length];
        var members = w.members + (w.members === 1 ? ' Member' : ' Members');
        var avatar = '<span class="h-9 w-9 rounded-md text-white grid place-items-center text-[13px] font-semibold shrink-0" style="background:' + color + '">' + esc(w.initial) + '</span>';
        if (w.current) {
          return '<div class="flex items-center gap-3 border border-brand/40 bg-sel/40 rounded-lg px-3 py-2.5">' + avatar +
            '<div class="min-w-0 flex-1"><div class="text-[14px] font-medium text-ink truncate">' + esc(w.name) +
            '<span class="text-[11px] bg-sel text-brand rounded px-1.5 py-0.5 ml-2">Current</span></div>' +
            '<div class="text-[12px] text-sub">' + esc(w.role) + ' &bull; ' + members + '</div></div></div>';
        }
        return '<form method="POST" action="' + w.switch_url + '" class="block"><input type="hidden" name="_token" value="' + CSRF + '" />' +
          '<button type="submit" class="w-full text-left flex items-center gap-3 border border-line rounded-lg px-3 py-2.5 hover:bg-hover hover:border-brand/40 transition-colors">' + avatar +
          '<div class="min-w-0 flex-1"><div class="text-[14px] font-medium text-ink truncate">' + esc(w.name) + '</div>' +
          '<div class="text-[12px] text-sub">' + esc(w.role) + ' &bull; ' + members + '</div></div>' +
          '<span class="text-[12px] text-link font-medium shrink-0">Switch</span></button></form>';
      }).join('');
      function openWs() { wsModal.classList.remove('hidden'); }
      function closeWs() { wsModal.classList.add('hidden'); }
      document.getElementById('switch-ws-btn').addEventListener('click', openWs);
      wsModal.addEventListener('click', function (e) { if (e.target.closest('[data-ws-close]')) closeWs(); });

      /* ---------- Projects grid (PRJ-010) ---------- */
      var grid = document.getElementById('projects-grid'), empty = document.getElementById('projects-empty');
      function card(p) {
        var coverStyle = p.cover_url ? 'background-image:url(\'' + esc(p.cover_url) + '\');background-size:cover;background-position:center' : 'background:' + p.gradient;
        var lead = p.lead
          ? '<span class="h-5 w-5 rounded-full bg-brand text-white grid place-items-center text-[9px] font-bold">' + esc(p.lead.initial) + '</span><span class="text-ink truncate">' + esc(p.lead.name) + '</span>'
          : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><span class="text-sub">No lead</span>';
        var vis = p.visibility === 'private'
          ? '<span class="inline-flex items-center gap-1 text-[11px] text-sub"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 11V8a4 4 0 018 0v3" stroke="currentColor" stroke-width="1.7"/></svg>Private</span>'
          : '<span class="inline-flex items-center gap-1 text-[11px] text-sub"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18" stroke="currentColor" stroke-width="1.6"/></svg>Public</span>';
        return '<a href="' + p.url + '" class="group block border border-line rounded-xl overflow-hidden hover:shadow-md transition-shadow">' +
          '<div class="relative h-24" style="' + coverStyle + '"><span class="absolute top-2.5 left-2.5 h-7 w-7 rounded-md bg-white/90 grid place-items-center text-[13px] font-semibold text-ink shadow-sm">' + esc(p.initial) + '</span></div>' +
          '<div class="p-4"><div class="flex items-center justify-between gap-2"><div class="text-[15px] font-semibold text-head truncate">' + esc(p.name) + '</div>' + vis + '</div>' +
          '<div class="text-[12px] text-sub mt-0.5">' + esc(p.identifier) + '</div>' +
          '<div class="flex items-center gap-1.5 text-[13px] mt-3 min-w-0">' + lead + '</div></div>' +
          '<div class="px-4 py-3 border-t border-line">' + (p.joined
            ? '<span class="inline-flex items-center text-[12px] font-medium text-green-700 bg-green-50 rounded px-2 py-0.5">Joined</span>'
            : '<span class="inline-flex items-center text-[12px] font-medium text-sub">Member</span>') + '</div></a>';
      }
      function renderGrid() {
        if (!PROJECTS.length) { grid.classList.add('hidden'); empty.classList.remove('hidden'); return; }
        grid.classList.remove('hidden'); empty.classList.add('hidden');
        grid.innerHTML = PROJECTS.map(card).join('');
      }
      renderGrid();

      /* ---------- Add Project modal (PRJ-020..028) ---------- */
      var modal = document.getElementById('add-project-modal');
      if (modal) {
        var nameEl = document.getElementById('proj-name');
        var idEl = document.getElementById('proj-id');
        var descEl = document.getElementById('proj-desc');
        var createBtn = document.getElementById('proj-create-btn');
        var idEdited = false;
        var access = CFG.defaultVisibility || 'public';
        var leadId = null;
        var coverFile = null;

        var GLOBE = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18" stroke="currentColor" stroke-width="1.6"/></svg>';
        var LOCK = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 11V8a4 4 0 018 0v3" stroke="currentColor" stroke-width="1.7"/></svg>';

        function clearErrors() { modal.querySelectorAll('[data-err]').forEach(function (el) { el.classList.add('hidden'); el.textContent = ''; }); }
        function showError(key, msg) { var el = modal.querySelector('[data-err="' + key + '"]'); if (el) { el.textContent = msg; el.classList.remove('hidden'); } }

        function open() {
          clearErrors();
          nameEl.value = ''; idEl.value = ''; descEl.value = '';
          idEdited = false; access = CFG.defaultVisibility || 'public'; leadId = null; coverFile = null;
          renderAccess(); renderLeadLabel(); resetCover();
          modal.classList.remove('hidden'); nameEl.focus();
        }
        function close() { modal.classList.add('hidden'); }

        var addBtn = document.getElementById('add-project-btn'); if (addBtn) addBtn.addEventListener('click', open);
        var addEmpty = document.getElementById('add-project-empty'); if (addEmpty) addEmpty.addEventListener('click', open);
        modal.addEventListener('click', function (e) { if (e.target.closest('[data-proj-close]')) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

        // Auto-derive Project ID from name until manually edited (PRJ-023)
        nameEl.addEventListener('input', function () {
          if (idEdited) return;
          idEl.value = nameEl.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, CFG.identifierMax || 10);
        });
        idEl.addEventListener('input', function () {
          idEl.value = idEl.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, CFG.identifierMax || 10);
          idEdited = idEl.value.length > 0;
        });

        // Access dropdown
        var accessBtn = document.getElementById('proj-access-btn'), accessMenu = document.getElementById('proj-access-menu');
        function renderAccess() {
          document.getElementById('proj-access-icon').innerHTML = access === 'private' ? LOCK : GLOBE;
          document.getElementById('proj-access-label').textContent = access === 'private' ? 'Private' : 'Public';
        }
        function accessOption(key, label, desc, icon) {
          return '<button type="button" data-access="' + key + '" class="w-full text-left flex items-start gap-2 px-3 py-2 hover:bg-hover">' +
            '<span class="mt-0.5 text-sub">' + icon + '</span><span><span class="block text-[13px] text-ink">' + label + '</span>' +
            '<span class="block text-[12px] text-sub">' + desc + '</span></span></button>';
        }
        accessMenu.innerHTML = accessOption('public', 'Public', 'Discoverable by workspace members.', GLOBE) + accessOption('private', 'Private', 'Only invited members can access.', LOCK);
        accessBtn.addEventListener('click', function (e) { e.stopPropagation(); accessMenu.classList.toggle('hidden'); });
        accessMenu.addEventListener('click', function (e) { var b = e.target.closest('[data-access]'); if (!b) return; access = b.getAttribute('data-access'); renderAccess(); accessMenu.classList.add('hidden'); });

        // Lead dropdown (PRJ-026)
        var leadBtn = document.getElementById('proj-lead-btn'), leadMenu = document.getElementById('proj-lead-menu');
        var leadSearch = document.getElementById('proj-lead-search'), leadList = document.getElementById('proj-lead-list');
        function renderLeadLabel() {
          var m = MEMBERS.find(function (x) { return x.id === leadId; });
          document.getElementById('proj-lead-label').textContent = m ? m.name : 'Lead';
          document.getElementById('proj-lead-icon').innerHTML = m
            ? '<span class="h-5 w-5 rounded-full bg-brand text-white grid place-items-center text-[9px] font-bold">' + esc(m.initial) + '</span>'
            : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
        }
        function renderLeadList(q) {
          q = (q || '').toLowerCase();
          var rows = MEMBERS.filter(function (m) { return (m.name + ' ' + m.email).toLowerCase().indexOf(q) !== -1; });
          var none = '<button type="button" data-lead="" class="w-full text-left flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-hover text-[13px] text-sub">No lead</button>';
          leadList.innerHTML = none + rows.map(function (m) {
            return '<button type="button" data-lead="' + m.id + '" class="w-full text-left flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-hover">' +
              '<span class="h-6 w-6 rounded-full bg-brand text-white grid place-items-center text-[10px] font-bold">' + esc(m.initial) + '</span>' +
              '<span class="min-w-0"><span class="block text-[13px] text-ink truncate">' + esc(m.name) + '</span><span class="block text-[11px] text-sub truncate">' + esc(m.email) + '</span></span></button>';
          }).join('');
        }
        leadBtn.addEventListener('click', function (e) { e.stopPropagation(); var willOpen = leadMenu.classList.contains('hidden'); leadMenu.classList.toggle('hidden'); if (willOpen) { renderLeadList(''); leadSearch.value = ''; leadSearch.focus(); } });
        leadSearch.addEventListener('input', function () { renderLeadList(leadSearch.value); });
        leadList.addEventListener('click', function (e) { var b = e.target.closest('[data-lead]'); if (!b) return; var v = b.getAttribute('data-lead'); leadId = v ? parseInt(v, 10) : null; renderLeadLabel(); leadMenu.classList.add('hidden'); });

        document.addEventListener('click', function (e) {
          if (!accessMenu.contains(e.target) && !accessBtn.contains(e.target)) accessMenu.classList.add('hidden');
          if (!leadMenu.contains(e.target) && !leadBtn.contains(e.target)) leadMenu.classList.add('hidden');
        });

        // Cover (PRJ-027) — local selection + preview
        var coverEl = document.getElementById('proj-cover');
        var coverInput = document.getElementById('proj-cover-input');
        var coverProgress = document.getElementById('proj-cover-progress');
        var coverName = document.getElementById('proj-cover-name');
        var DEFAULT_COVER = 'linear-gradient(120deg,#f6d5b8 0%,#eaa987 55%,#d98a68 100%)';
        function resetCover() { coverFile = null; coverEl.style.background = DEFAULT_COVER; coverEl.style.backgroundImage = ''; coverProgress.classList.add('hidden'); coverInput.value = ''; }
        document.getElementById('proj-change-cover').addEventListener('click', function () { coverInput.click(); });
        document.getElementById('proj-cover-clear').addEventListener('click', function () { resetCover(); });
        coverInput.addEventListener('change', function () {
          var f = coverInput.files && coverInput.files[0]; if (!f) return;
          var maxKb = CFG.coverMaxKb || 5120;
          if (f.size / 1024 > maxKb) { showError('form', 'The cover image is too large (max ' + Math.round(maxKb / 1024) + ' MB).'); coverInput.value = ''; return; }
          coverFile = f;
          var url = URL.createObjectURL(f);
          coverEl.style.backgroundImage = 'url(\'' + url + '\')'; coverEl.style.backgroundSize = 'cover'; coverEl.style.backgroundPosition = 'center';
          coverName.textContent = f.name; coverProgress.classList.remove('hidden');
        });

        renderAccess(); renderLeadLabel();

        // Create (PRJ-028) — submit, prevent double-submit, preserve values on error
        var submitting = false;
        createBtn.addEventListener('click', function () {
          if (submitting) return;
          clearErrors();
          if (!nameEl.value.trim()) { showError('name', 'Project name is required.'); nameEl.focus(); return; }
          if (!idEl.value.trim()) { showError('identifier', 'Project ID is required.'); idEl.focus(); return; }

          submitting = true; createBtn.disabled = true; createBtn.textContent = 'Creating…';
          var fd = new FormData();
          fd.append('name', nameEl.value.trim());
          fd.append('identifier', idEl.value.trim());
          fd.append('description', descEl.value);
          fd.append('visibility', access);
          if (leadId) fd.append('lead_user_id', leadId);
          if (coverFile) fd.append('cover', coverFile);

          fetch(EP.store, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
            .then(function (res) {
              if (res.status === 201 && res.data.redirect) { window.location.href = res.data.redirect; return; }
              submitting = false; createBtn.disabled = false; createBtn.textContent = 'Create project';
              if (res.status === 422 && res.data.errors) {
                var errs = res.data.errors;
                Object.keys(errs).forEach(function (field) {
                  var key = field === 'lead_user_id' ? 'form' : (field === 'cover' ? 'form' : field);
                  showError(key, errs[field][0]);
                });
              } else {
                showError('form', (res.data && res.data.message) || 'Something went wrong. Please try again.');
              }
            })
            .catch(function () { submitting = false; createBtn.disabled = false; createBtn.textContent = 'Create project'; showError('form', 'Network error. Your details are kept — please retry.'); });
        });
      }

      /* ---------- Toast auto-dismiss ---------- */
      var toast = document.getElementById('toast');
      if (toast) setTimeout(function () { toast.style.transition = 'opacity .4s'; toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 400); }, 4000);
    })();
  </script>
@endsection
