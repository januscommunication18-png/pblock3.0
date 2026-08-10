<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>" />
  <title>Projects — <?php echo e($workspace->name); ?></title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="<?php echo e(asset('assets/js/tailwind.config.js')); ?>"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo e(asset('assets/css/styles.css')); ?>" />
  <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
  <script defer src="<?php echo e(asset('assets/js/settings/app.js')); ?>"></script>
  <script defer src="<?php echo e(asset('assets/js/projects/index.js')); ?>"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  <header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
    <button id="open-sidebar" class="lg:hidden h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <a href="<?php echo e(route('welcome')); ?>" class="flex items-center gap-2">
      <span class="h-6 w-6 rounded-md bg-slate-700 text-white grid place-items-center text-[11px] font-semibold"><?php echo e($workspace->initial()); ?></span>
      <span class="font-medium text-[13px] max-w-[160px] truncate"><?php echo e($workspace->name); ?></span>
    </a>
    <span class="text-faint">/</span>
    <span class="text-[13px] text-ink font-medium">Projects</span>
    <div class="ml-auto flex items-center gap-2">
      <?php if($canCreateProject): ?>
        <a href="<?php echo e(route('projects.index')); ?>?create=1" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          New project
        </a>
      <?php endif; ?>
      <a href="<?php echo e(route('settings.general')); ?>" title="Workspace settings" class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9c.2.62.78 1.04 1.43 1.05H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>
      </a>
    </div>
  </header>

  <div class="flex-1 flex min-h-0 relative">
    <?php echo $__env->make('partials.app-sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <main class="flex-1 min-w-0 overflow-y-auto">
      <div id="settings-root" data-bootstrap='<?php echo json_encode($bootstrap, 15, 512) ?>'>
        <div class="max-w-[1100px] mx-auto px-5 sm:px-8 py-10 text-sub text-[13px]">Loading projects…</div>
      </div>
    </main>
  </div>
</body>
</html>
<?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/projects/index.blade.php ENDPATH**/ ?>