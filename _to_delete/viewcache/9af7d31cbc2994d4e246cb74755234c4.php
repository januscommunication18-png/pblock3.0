
<!-- AppRail -->
<nav class="hidden lg:flex w-16 shrink-0 border-r border-line bg-[#f6f7f8] flex-col items-center py-3 gap-1">
  <a href="<?php echo e(route('projects.index')); ?>" class="flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg bg-sel text-brand">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/></svg>
    <span class="text-[10px] text-center leading-tight">Projects</span>
  </a>
  <a href="<?php echo e(route('settings.general')); ?>" title="Workspace settings"
     class="mt-auto flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg text-sub hover:bg-hover hover:text-ink">
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9c.2.62.78 1.04 1.43 1.05H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>
    <span class="text-[10px] text-center leading-tight">Settings</span>
  </a>
</nav>

<div id="sidebar-backdrop" class="hidden lg:hidden fixed inset-0 bg-black/30 z-30"></div>

<!-- AppSidebar -->
<aside id="sidebar" class="w-60 shrink-0 border-r border-line bg-white flex flex-col fixed lg:relative inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-200">
  <div class="flex items-center gap-2 px-4 h-12 shrink-0">
    <span class="font-semibold text-ink truncate"><?php echo e($workspace->name); ?></span>
    <button id="close-sidebar" class="lg:hidden ml-auto h-7 w-7 grid place-items-center rounded hover:bg-hover"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
  </div>
  <div class="px-2 overflow-y-auto flex-1">
    
    <button class="w-full flex items-center justify-center gap-2 px-2 h-9 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold mb-2 transition-colors">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      New work item
    </button>
    <a href="<?php echo e(route('welcome')); ?>" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M4 11l8-6 8 6v8a1 1 0 01-1 1h-4v-6H9v6H5a1 1 0 01-1-1v-8z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>Home</a>
    <a href="#" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4 12.5-12.5z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Drafts</a>
    <a href="#" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0114 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>Your work</a>
    <a href="#" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M14 20v-4a2 2 0 012-2h4" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>Stickies</a>

    
    <details class="mt-3">
      <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
        <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Workspace</span>
        <svg class="pb-chev ml-auto text-faint transition-transform" width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </summary>
      <div class="mt-0.5 space-y-0.5">
        <a href="<?php echo e(route('settings.general')); ?>" class="flex items-center gap-2 pl-4 pr-2 h-8 rounded-md text-ink hover:bg-hover">Settings</a>
        <a href="<?php echo e(route('settings.members')); ?>" class="flex items-center gap-2 pl-4 pr-2 h-8 rounded-md text-ink hover:bg-hover">Members</a>
      </div>
    </details>

    
    <details open class="mt-1">
      <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
        <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Projects</span>
        <span class="ml-auto flex items-center gap-1">
          <?php if($canCreateProject): ?>
            <a href="<?php echo e(route('projects.index')); ?>?create=1" onclick="event.stopPropagation()" title="New project" class="h-5 w-5 grid place-items-center rounded hover:bg-line text-sub">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </a>
          <?php endif; ?>
          <svg class="pb-chev text-faint transition-transform" width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
      </summary>
      <div class="mt-0.5 space-y-0.5">
        <?php $__empty_1 = true; $__currentLoopData = $projects; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
          <a href="<?php echo e($p['url']); ?>" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover">
            <span class="w-4 text-center shrink-0"><?php echo e($p['emoji'] ?: '📁'); ?></span>
            <span class="truncate"><?php echo e($p['name']); ?></span>
          </a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
          <?php if($canCreateProject): ?>
            <a href="<?php echo e(route('projects.index')); ?>?create=1" class="flex items-center gap-2 px-2 h-8 rounded-md text-sub hover:bg-hover text-[12px]">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              Create your first project
            </a>
          <?php else: ?>
            <div class="px-2 h-8 flex items-center text-[12px] text-faint">No projects yet</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </details>
  </div>
  <style>details[open] > summary .pb-chev { transform: rotate(180deg); }</style>
  <div class="px-4 py-2 border-t border-line text-[12px] text-sub shrink-0">Business trial ends in 13d</div>
</aside>

<script>
  (function () {
    var sb = document.getElementById('sidebar'), bd = document.getElementById('sidebar-backdrop');
    var open = document.getElementById('open-sidebar'), close = document.getElementById('close-sidebar');
    function show() { sb.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); }
    function hide() { sb.classList.add('-translate-x-full'); bd.classList.add('hidden'); }
    open && open.addEventListener('click', show);
    close && close.addEventListener('click', hide);
    bd && bd.addEventListener('click', hide);
  })();
</script>
<?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/partials/app-sidebar.blade.php ENDPATH**/ ?>