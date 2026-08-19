{{-- The application icon rail — Projects, Wiki, Help Center, Settings.

     Extracted from partials.app-sidebar so a module can have the rail WITHOUT the Projects
     sidebar beside it. The Help Center needs exactly that: its own navigation replaces the
     project list rather than sitting under it, and a Help Desk screen showing a workspace's
     projects is the Project navigation leaking into a module it does not belong to.

     app-sidebar includes this, so the rail is still defined once for the screens that want
     both. Resolves `$workspace` defensively, like app-sidebar, so it can be included anywhere
     an authenticated user is. --}}
@php($__ws = $workspace ?? optional(auth()->user())->currentWorkspace)
@php($__wiki = app(\App\Services\WorkspaceApps::class)->isEnabled($__ws, 'wiki'))
@php($__helpdesk = app(\App\Services\WorkspaceApps::class)->isEnabled($__ws, 'helpdesk'))

<!-- AppRail -->
<nav class="hidden lg:flex w-16 shrink-0 border-r border-line bg-[#f6f7f8] flex-col items-center py-3 gap-1">
  <a href="{{ route('projects.index') }}" @class(['flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg', 'text-sub hover:bg-hover hover:text-ink' => request()->is('wiki*'), 'bg-sel text-brand' => ! request()->is('wiki*')])>
    {!! pb_icon('grid', 18) !!}
    <span class="text-[10px] text-center leading-tight">Projects</span>
  </a>
  @if ($__wiki)
    <a href="{{ route('wiki.home') }}" @class(['flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg', 'bg-sel text-brand' => request()->is('wiki*'), 'text-sub hover:bg-hover hover:text-ink' => ! request()->is('wiki*')])>
      {!! pb_icon('file-lines', 18) !!}
      <span class="text-[10px] text-center leading-tight">Wiki</span>
    </a>
  @endif
  @if ($__helpdesk)
    <a href="{{ route('help-center.index') }}" @class(['flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg', 'bg-sel text-brand' => request()->is('help-center*'), 'text-sub hover:bg-hover hover:text-ink' => ! request()->is('help-center*')])>
      {!! pb_icon('inbox', 18) !!}
      <span class="text-[10px] text-center leading-tight">Help Center</span>
    </a>
  @endif
  <a href="{{ route('settings.general') }}" title="Workspace settings"
     class="mt-auto flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg text-sub hover:bg-hover hover:text-ink">
    {!! pb_icon('gear', 19) !!}
    <span class="text-[10px] text-center leading-tight">Settings</span>
  </a>
</nav>
