<?php

// FIRST: tenant.php is domain-constrained, and every route below it is NOT — an unconstrained
// route matches any host, including a tenant's. Registered later, a tenant `/help` would lose
// to whichever central route claimed the path first, silently. See that file's header.
require __DIR__.'/tenant.php';

require __DIR__.'/auth.php';
/*
 * The Back Office (docs/features/backoffice-auth.md).
 *
 * Inside the `web` group so it gets sessions and CSRF, but on its own guard — being signed in
 * as a customer grants nothing here, and vice versa (§9, BO-D1).
 */
require __DIR__.'/backoffice.php';
require __DIR__.'/invitation.php';
require __DIR__.'/workspace.php';
require __DIR__.'/project.php';
require __DIR__.'/drafts.php';
require __DIR__.'/your-work.php';
require __DIR__.'/inbox.php';
require __DIR__.'/search.php';
require __DIR__.'/help-center.php';
require __DIR__.'/quick-create.php';
require __DIR__.'/settings.php';
require __DIR__.'/account.php';

// LAST: wiki.php ends with the public /{workspace}/{slug} pattern, which must lose to
// every real route in the application.
require __DIR__.'/wiki.php';
