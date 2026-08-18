<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'avatar_disk' => env('AVATAR_DISK', 'assets'),

    /*
     * Disk for account profile images (avatar + cover), written and streamed by
     * Account\ProfileController. These are PRIVATE objects served through an authorising
     * route, never a public URL — see that controller's docblock. Kept separate from
     * `avatar_disk` because that one holds public onboarding avatars; the two differ in
     * visibility, so one switch must not silently flip the other.
     */
    'profile_disk' => env('PROFILE_DISK', 'local'),

    /*
     * Disk for work-item and draft attachments ("Add files"), written by
     * WorkItemMediaController and DraftMediaController. Also PRIVATE and streamed behind the
     * project's `view` ability. Each row records the disk it was written to, so changing this
     * only affects NEW uploads — existing rows keep streaming from wherever they already are.
     */
    'media_disk' => env('MEDIA_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'assets' => [
            'driver' => 'local',
            'root' => public_path('assets'),
            'url' => env('APP_URL').'/assets',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'spaces' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY'),
            'secret' => env('DO_SPACES_SECRET'),
            'region' => env('DO_SPACES_REGION', 'nyc3'),
            'bucket' => env('DO_SPACES_BUCKET'),
            'endpoint' => env('DO_SPACES_ENDPOINT'),
            'url' => env('DO_SPACES_URL'),
            'use_path_style_endpoint' => false,
            // Default for writes that do not name a visibility. Callers storing private
            // objects (profile images) pass 'private' explicitly and override this.
            'visibility' => 'public',
            // Loud on purpose. With `false`, a rejected PutObject returns as though it
            // succeeded and the upload silently never lands in the bucket — which is
            // exactly how a read-only key went unnoticed here.
            'throw' => true,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
