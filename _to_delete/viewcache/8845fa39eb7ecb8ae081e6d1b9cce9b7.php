<?php $__env->startSection('title', 'Onboarding · Invite — Project Block'); ?>

<?php $__env->startSection('body'); ?>
  <div class="h-1 w-full bg-line"><div class="h-full bg-brand" style="width:100%"></div></div>

  <header class="flex items-center justify-between px-5 sm:px-10 py-5">
    <div class="flex items-center gap-3">
      <a href="<?php echo e(route('onboarding.workspace')); ?>" class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
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
      <h1 class="text-[24px] font-bold text-head">Invite your teammates</h1>
      <p class="text-[15px] text-sub mb-7">Work in Project Block happens best with your team. Invite them now to use Project Block to its potential.</p>

      <form method="POST" action="<?php echo e(route('onboarding.invite.store')); ?>" id="invite-form">
        <?php echo csrf_field(); ?>
        <div class="grid grid-cols-[1fr_130px] gap-3 mb-2">
          <span class="text-[13px] font-medium text-ink">Email</span>
          <span class="text-[13px] font-medium text-ink">Role</span>
        </div>
        <div id="invite-list" class="space-y-3"
             data-roles='<?php echo json_encode($roleLabels, 15, 512) ?>'
             data-invite-roles='<?php echo json_encode(array_values($roles), 15, 512) ?>'
             data-rows="<?php echo e($rows); ?>"></div>

        <button type="button" id="add-invite" class="flex items-center gap-1.5 mt-4 text-[14px] font-semibold text-link hover:underline">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Add another
        </button>

        <button type="submit" id="continue" class="mt-7 w-full h-11 rounded-lg text-[14px] font-semibold bg-brand hover:bg-brand-dark text-white transition-colors">Continue</button>
      </form>

      <form method="POST" action="<?php echo e(route('onboarding.invite.skip')); ?>">
        <?php echo csrf_field(); ?>
        <button type="submit" class="block text-center w-full h-9 leading-9 mt-3 text-[14px] font-semibold text-ink hover:underline">I'll do it later</button>
      </form>
    </div>
  </main>

  <script>
    (function () {
      var listEl = document.getElementById('invite-list');
      var ROLE_LABELS = JSON.parse(listEl.getAttribute('data-roles'));      // {key: Label}
      var ROLE_KEYS = JSON.parse(listEl.getAttribute('data-invite-roles')); // [key,...]
      var PH = ['charlie.taylor@company.com', 'octave.chanute@company.com', 'george.spratt@company.com'];
      var idx = 0;

      function roleOptions() {
        return ROLE_KEYS.map(function (k) {
          return '<li role="option" data-val="' + k + '" class="group relative flex items-center cursor-pointer select-none py-2 pl-3 pr-9 text-[14px] text-ink hover:bg-brand hover:text-white">' +
            '<span class="block truncate">' + ROLE_LABELS[k] + '</span>' +
            '<span class="pb-combo-check hidden absolute inset-y-0 right-0 flex items-center pr-3 text-brand group-hover:text-white"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
            '</li>';
        }).join('');
      }
      function rowHtml(i) {
        return '<div class="grid grid-cols-[1fr_130px] gap-3">' +
          '<input type="email" name="invites[' + i + '][email]" placeholder="' + (PH[i] || 'name@company.com') + '" class="pb-input" />' +
          '<div class="relative pb-combo">' +
            '<input type="hidden" name="invites[' + i + '][role]" class="pb-combo-input" />' +
            '<button type="button" class="pb-combo-btn pb-input text-left cursor-pointer" aria-haspopup="listbox" aria-expanded="false">' +
              '<span class="flex items-center justify-between h-full">' +
                '<span class="pb-combo-value text-faint truncate">Select role</span>' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0 ml-1"><path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
              '</span>' +
            '</button>' +
            '<ul role="listbox" class="pb-combo-list hidden absolute z-40 mt-1 w-full max-h-56 overflow-auto rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5">' + roleOptions() + '</ul>' +
          '</div>' +
        '</div>';
      }
      function addRow() { listEl.insertAdjacentHTML('beforeend', rowHtml(idx)); idx++; }

      var initial = parseInt(listEl.getAttribute('data-rows'), 10) || 3;
      for (var r = 0; r < initial; r++) addRow();
      document.getElementById('add-invite').addEventListener('click', addRow);

      function closeAllCombos() {
        listEl.querySelectorAll('.pb-combo-list').forEach(function (ul) { ul.classList.add('hidden'); });
      }
      listEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.pb-combo-btn');
        if (btn) {
          e.stopPropagation();
          var ul = btn.parentElement.querySelector('.pb-combo-list');
          var willOpen = ul.classList.contains('hidden');
          closeAllCombos();
          if (willOpen) ul.classList.remove('hidden');
          return;
        }
        var li = e.target.closest('.pb-combo-list [data-val]');
        if (li) {
          var combo = li.closest('.pb-combo');
          var valEl = combo.querySelector('.pb-combo-value');
          var hidden = combo.querySelector('.pb-combo-input');
          var key = li.getAttribute('data-val');
          hidden.value = key;
          valEl.textContent = ROLE_LABELS[key];
          valEl.classList.remove('text-faint');
          valEl.classList.add('text-ink');
          combo.querySelectorAll('.pb-combo-check').forEach(function (c) { c.classList.add('hidden'); });
          li.querySelector('.pb-combo-check').classList.remove('hidden');
          combo.querySelector('.pb-combo-list').classList.add('hidden');
        }
      });
      document.addEventListener('click', function (e) {
        if (!e.target.closest('.pb-combo')) closeAllCombos();
      });
    })();
  </script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.auth', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/onboarding/invite.blade.php ENDPATH**/ ?>