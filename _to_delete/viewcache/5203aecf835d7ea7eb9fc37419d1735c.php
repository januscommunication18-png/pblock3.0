<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>" />
  <title><?php echo $__env->yieldContent('title', 'Project Block'); ?></title>

  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="<?php echo e(asset('assets/js/tailwind.config.js')); ?>"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo e(asset('assets/css/styles.css')); ?>" />
</head>
<body class="bg-white text-ink min-h-screen flex flex-col">
  <?php echo $__env->yieldContent('body'); ?>
</body>
</html>
<?php /**PATH /Users/rohitphilip/Sites/pblock3.0/resources/views/layouts/auth.blade.php ENDPATH**/ ?>