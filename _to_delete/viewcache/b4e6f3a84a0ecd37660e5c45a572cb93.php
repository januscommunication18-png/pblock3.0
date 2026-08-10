<?php $__env->startPush('section-script'); ?>
  
  <link rel="stylesheet" href="https://unpkg.com/tabulator-tables@6.3.1/dist/css/tabulator.min.css" />
  <link rel="stylesheet" href="<?php echo e(asset('assets/css/tabulator-skin.css')); ?>" />
  <script src="https://unpkg.com/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>
  <script defer src="<?php echo e(asset('assets/js/settings/members.js')); ?>"></script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('settings.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/settings/members.blade.php ENDPATH**/ ?>