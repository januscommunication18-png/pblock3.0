<?php $__env->startSection('title', 'Onboarding · Workspace — Project Block'); ?>

<?php $__env->startSection('body'); ?>
  <div class="h-1 w-full bg-line"><div class="h-full bg-brand" style="width:80%"></div></div>

  <header class="flex items-center justify-between px-5 sm:px-10 py-5">
    <div class="flex items-center gap-3">
      <a href="<?php echo e(route('onboarding.goals')); ?>" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <a class="flex items-center gap-2" href="#">
        <svg width="24" height="24" viewBox="0 0 32 32" fill="#0f0f10"><path d="M5 21 L15 4 L20.5 4 L10.5 21 Z"/><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z"/></svg>
        <span class="text-[18px] font-bold tracking-tight text-head">Project Block</span>
      </a>
    </div>
    <div class="flex items-center gap-2">
      <div class="flex items-center gap-2 border border-line rounded-full pl-1 pr-3 py-1 text-[13px] text-ink">
        <span class="h-5 w-5 rounded-full bg-brand grid place-items-center text-white text-[9px] font-bold"><?php echo e($user->initial()); ?></span>
        <span><?php echo e($user->displayName()); ?></span>
      </div>
      <form method="POST" action="<?php echo e(route('logout')); ?>">
        <?php echo csrf_field(); ?>
        <button type="submit" title="Log out" aria-label="Log out" class="flex items-center gap-1.5 h-8 px-3 rounded-full border border-line text-[13px] text-sub hover:bg-hover hover:text-ink transition-colors">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 21H6a2 2 0 01-2-2V5a2 2 0 012-2h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="hidden sm:inline">Log out</span>
        </button>
      </form>
    </div>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[430px] py-6 sm:py-12">
      <h1 class="text-[24px] font-bold text-head">Create your workspace</h1>
      <p class="text-[15px] text-sub mb-7">All your work — unified.</p>

      <form method="POST" action="<?php echo e(route('onboarding.workspace.store')); ?>" id="ws-form">
        <?php echo csrf_field(); ?>
        <label class="block text-[13px] font-medium text-ink mb-1.5" for="ws-name">Name your workspace <span class="text-danger">*</span></label>
        <input id="ws-name" name="name" type="text" value="<?php echo e(old('name')); ?>" placeholder="Acme Inc" class="pb-input" autocomplete="off" />
        <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-[12px] text-danger mt-1.5"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>

        <label class="block text-[13px] font-medium text-ink mb-1.5 mt-5">Set your workspace's URL <span class="text-danger">*</span></label>
        <div class="pb-group">
          <span class="pb-group__prefix">app.projectblock.so/</span>
          <input id="ws-slug" name="slug" type="text" value="<?php echo e(old('slug')); ?>" placeholder="acme-inc" class="pb-group__field" autocomplete="off" />
        </div>
        <p class="text-[12px] text-sub mt-1.5">You can only edit the slug of the URL</p>
        <?php $__errorArgs = ['slug'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-[12px] text-danger mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>

        <p class="text-[13px] font-medium text-ink mb-3 mt-5">How many people will use this workspace? <span class="text-danger">*</span></p>
        <input type="hidden" name="team_size" id="team_size" value="<?php echo e(old('team_size')); ?>" />
        <div id="size-list" class="flex flex-wrap gap-2.5"
             data-sizes='<?php echo json_encode($teamSizes, 15, 512) ?>'></div>
        <?php $__errorArgs = ['team_size'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-[12px] text-danger mt-2"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>

        <button id="continue" type="submit" disabled class="mt-7 w-full h-11 rounded-lg text-[14px] font-semibold bg-hover text-faint cursor-not-allowed transition-colors">Create workspace</button>
      </form>
    </div>
  </main>

  <script>
    (function () {
      var SIZES = JSON.parse(document.getElementById('size-list').getAttribute('data-sizes'));
      var nameEl = document.getElementById('ws-name');
      var slugEl = document.getElementById('ws-slug');
      var sizeEl = document.getElementById('team_size');
      var cont = document.getElementById('continue');
      var slugTouched = <?php echo e(old('slug') ? 'true' : 'false'); ?>;

      function render() {
        document.getElementById('size-list').innerHTML = SIZES.map(function (s) {
          var sel = sizeEl.value === s;
          return '<button type="button" data-size="' + s + '" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border text-[13px] ' +
            (sel ? 'border-brand ring-1 ring-brand text-brand font-medium' : 'border-stroke text-ink hover:bg-hover') + '">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/>' +
            (sel ? '<path d="M8 12l2.5 2.5L16 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' : '') +
            '</svg>' + s + '</button>';
        }).join('');
        document.querySelectorAll('#size-list [data-size]').forEach(function (el) {
          el.onclick = function () { sizeEl.value = el.getAttribute('data-size'); render(); gate(); };
        });
      }
      function gate() {
        var ok = nameEl.value.trim().length > 0 && slugEl.value.trim().length > 0 && !!sizeEl.value;
        cont.disabled = !ok;
        cont.className = 'mt-7 w-full h-11 rounded-lg text-[14px] font-semibold transition-colors ' +
          (ok ? 'bg-brand hover:bg-brand-dark text-white cursor-pointer' : 'bg-hover text-faint cursor-not-allowed');
      }
      function slugify(v) { return v.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
      nameEl.addEventListener('input', function () {
        if (!slugTouched) slugEl.value = slugify(nameEl.value);
        gate();
      });
      slugEl.addEventListener('input', function () { slugTouched = true; slugEl.value = slugify(slugEl.value); gate(); });
      render(); gate();
    })();
  </script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.auth', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/onboarding/workspace.blade.php ENDPATH**/ ?>