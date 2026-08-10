<?php $__env->startSection('title', 'Invite teammates — Project Block'); ?>

<?php $__env->startSection('body'); ?>
  <!-- Minimal header: back (left) · title · close (right) — matches Create workspace -->
  <header class="relative h-14 shrink-0 border-b border-line flex items-center px-4">
    <a href="<?php echo e(route('welcome')); ?>" title="Back" class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <span class="absolute left-1/2 -translate-x-1/2 font-semibold text-[15px] text-head">Invite teammates</span>
    <a href="<?php echo e(route('welcome')); ?>" title="Close" class="ml-auto h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </a>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[460px] py-10 sm:py-14">
      <h1 class="text-[24px] font-bold text-head">Invite your teammates</h1>
      <p class="text-[15px] text-sub mb-8">Add people to <span class="font-medium text-ink"><?php echo e($workspace->name); ?></span>. They'll appear as pending until they accept.</p>

      <form method="POST" action="<?php echo e(route('workspaces.invite.store')); ?>" id="invite-form">
        <?php echo csrf_field(); ?>
        <div class="grid grid-cols-[1fr_140px] gap-3 mb-2">
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

        <div class="flex items-center gap-3 mt-8">
          <button type="submit" id="send" class="h-10 px-5 rounded-md text-[14px] font-semibold bg-brand hover:bg-brand-dark text-white transition-colors">Send invitations</button>
          <a href="<?php echo e(route('welcome')); ?>" class="h-10 px-5 grid place-items-center rounded-md border border-stroke text-[14px] font-semibold text-ink hover:bg-hover">Go back</a>
        </div>
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
        return '<div class="grid grid-cols-[1fr_140px] gap-3">' +
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

<?php echo $__env->make('layouts.auth', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/workspace/invite.blade.php ENDPATH**/ ?>