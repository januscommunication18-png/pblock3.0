<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>" />
  <title>Workspace settings · <?php echo e(ucfirst($section)); ?> — Project Block</title>

  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="<?php echo e(asset('assets/js/tailwind.config.js')); ?>"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo e(asset('assets/css/styles.css')); ?>" />

  
  <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
  <script defer src="<?php echo e(asset('assets/js/settings/app.js')); ?>"></script>
  <?php echo $__env->yieldPushContent('section-script'); ?>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  
  <header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
    <button id="open-nav" class="md:hidden h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <span class="flex items-center gap-2">
      <span class="h-6 w-6 rounded-md bg-slate-700 text-white grid place-items-center text-[11px] font-semibold"><?php echo e($workspace->initial()); ?></span>
      <span class="font-medium text-[13px] max-w-[160px] truncate"><?php echo e($workspace->name); ?></span>
      <span class="text-faint">/</span>
      <span class="text-[13px] text-sub">Settings</span>
    </span>
    <div class="ml-auto flex items-center gap-2">
      <span class="h-6 w-px bg-line"></span>
      <a href="<?php echo e(route('welcome')); ?>" title="Close settings" class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </a>
    </div>
  </header>

  <div class="flex-1 flex min-h-0 relative">
    <div id="nav-backdrop" class="hidden md:hidden fixed inset-0 bg-black/30 z-30"></div>

    
    <aside id="settings-nav" class="w-64 shrink-0 border-r border-line bg-white flex flex-col fixed md:relative inset-y-0 left-0 z-40 -translate-x-full md:translate-x-0 transition-transform duration-200">
      <div class="md:hidden flex justify-end px-2 h-12 items-center shrink-0">
        <button id="close-nav" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
      </div>
      <nav class="flex-1 overflow-y-auto pt-2 pb-6 px-2">
        <?php $__currentLoopData = $nav; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $group => $items): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <div class="px-2 h-8 mt-3 first:mt-1 flex items-center text-[11px] font-semibold text-faint uppercase tracking-wide"><?php echo e($group); ?></div>
          <?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php $active = $item['key'] === $section; ?>
            <?php if($item['status'] === 'soon'): ?>
              <span class="flex items-center gap-2 px-2 h-8 rounded-md text-faint cursor-not-allowed select-none">
                <span class="truncate"><?php echo e($item['label']); ?></span>
                <span class="ml-auto text-[10px] bg-hover text-sub rounded px-1.5 py-0.5">Soon</span>
              </span>
            <?php else: ?>
              <a href="<?php echo e(url('/settings/'.$item['key'])); ?>"
                 class="flex items-center gap-2 px-2 h-8 rounded-md <?php echo e($active ? 'bg-sel text-brand font-medium' : ($item['status'] === 'placeholder' ? 'text-sub hover:bg-hover' : 'text-ink hover:bg-hover')); ?>">
                <span class="truncate"><?php echo e($item['label']); ?></span>
              </a>
            <?php endif; ?>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </nav>
    </aside>

    
    <main class="flex-1 min-w-0 overflow-y-auto">
      <div id="settings-root"
           data-section="<?php echo e($section); ?>"
           data-bootstrap='<?php echo json_encode($bootstrap, 15, 512) ?>'>
        
        <div class="max-w-[820px] mx-auto px-5 sm:px-8 py-10 text-sub text-[13px]">Loading <?php echo e(ucfirst($section)); ?> settings…</div>
      </div>
    </main>
  </div>

  <script>
    // Mobile settings-nav drawer (parity with the app shell).
    (function () {
      var nav = document.getElementById('settings-nav');
      var bd = document.getElementById('nav-backdrop');
      var open = document.getElementById('open-nav');
      var close = document.getElementById('close-nav');
      function show() { nav.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); }
      function hide() { nav.classList.add('-translate-x-full'); bd.classList.add('hidden'); }
      open && open.addEventListener('click', show);
      close && close.addEventListener('click', hide);
      bd && bd.addEventListener('click', hide);
    })();
  </script>
</body>
</html>
<?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/settings/layout.blade.php ENDPATH**/ ?>