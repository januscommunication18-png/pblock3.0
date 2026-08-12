<?php

/*
|--------------------------------------------------------------------------
| Onboarding options (Phase 1)
|--------------------------------------------------------------------------
| Copy to config/onboarding.php. Roles are single-select (§7); goals are
| multi-select (§8). Both are personalization metadata only — never permissions.
*/

return [
    'roles' => [
        'pm' => 'Product Manager',
        'em' => 'Engineering Manager',
        'des' => 'Designer',
        'dev' => 'Developer',
        'fnd' => 'Founder/Executive',
        'ops' => 'Operations Manager',
        'oth' => 'Others',
    ],

    'goals' => [
        'roadmaps' => 'Plan and track product roadmaps',
        'sprints' => 'Manage engineering sprints',
        'cross' => 'Coordinate cross-functional projects',
        'replace' => 'Replace our current tool',
        'explore' => 'Just exploring',
    ],
];
