<?php

require __DIR__.'/auth.php';
require __DIR__.'/invitation.php';
require __DIR__.'/workspace.php';
require __DIR__.'/project.php';
require __DIR__.'/drafts.php';
require __DIR__.'/your-work.php';
require __DIR__.'/inbox.php';
require __DIR__.'/search.php';
require __DIR__.'/help-desk.php';
require __DIR__.'/quick-create.php';
require __DIR__.'/settings.php';
require __DIR__.'/account.php';

// LAST: wiki.php ends with the public /{workspace}/{slug} pattern, which must lose to
// every real route in the application.
require __DIR__.'/wiki.php';
