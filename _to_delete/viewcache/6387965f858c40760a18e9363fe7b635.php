<?php $__env->startPush('section-script'); ?>
  <script defer>
    document.addEventListener('DOMContentLoaded', function () {
      var root = document.getElementById('settings-root');
      if (!root) return;
      var b = {}; try { b = JSON.parse(root.getAttribute('data-bootstrap') || '{}'); } catch (e) {}
      var badge = b.comingSoon
        ? '<span class="inline-block text-[11px] bg-amber-100 text-amber-700 rounded-md px-2 py-0.5 mb-3">Coming soon</span>'
        : '';
      root.innerHTML =
        '<div class="max-w-[820px] mx-auto px-5 sm:px-8 py-16 text-center">' +
        badge +
        '<h1 class="text-[20px] font-bold text-head">' + (b.label || 'Settings') + '</h1>' +
        '<p class="text-[13px] text-sub mt-2 max-w-md mx-auto">' +
        (b.comingSoon
          ? 'This section is on the way and will be available in a future release.'
          : 'This section isn\'t part of this release yet.') +
        '</p></div>';
    });
  </script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('settings.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/settings/placeholder.blade.php ENDPATH**/ ?>