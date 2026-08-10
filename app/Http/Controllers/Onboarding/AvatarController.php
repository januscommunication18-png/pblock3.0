<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AvatarRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Avatar upload (ONB-002). Stores on the configured disk:
 *   local  -> 'assets'  (public/assets/avatars, url /assets/avatars/...)
 *   prod   -> 'spaces'  (DigitalOcean Spaces, S3 driver)
 * Disk chosen by config('filesystems.avatar_disk'); no code change between envs (D-A6).
 * Returns JSON so the client can drive the progress bar via XHR/fetch.
 */
class AvatarController extends Controller
{
    public function store(AvatarRequest $request): JsonResponse
    {
        $user = Auth::user();
        $disk = config('filesystems.avatar_disk', 'assets');

        // Remove a previous upload if it lived on the same disk.
        if ($user->avatar_url && str_contains($user->avatar_url, '/avatars/')) {
            $old = 'avatars/'.basename($user->avatar_url);
            if (Storage::disk($disk)->exists($old)) {
                Storage::disk($disk)->delete($old);
            }
        }

        $path = $request->file('avatar')->store('avatars', ['disk' => $disk, 'visibility' => 'public']);
        $url  = Storage::disk($disk)->url($path);

        $user->forceFill(['avatar_url' => $url])->save();

        return response()->json(['url' => $url]);
    }

    public function destroy(): JsonResponse
    {
        $user = Auth::user();
        $disk = config('filesystems.avatar_disk', 'assets');

        if ($user->avatar_url && str_contains($user->avatar_url, '/avatars/')) {
            $old = 'avatars/'.basename($user->avatar_url);
            if (Storage::disk($disk)->exists($old)) {
                Storage::disk($disk)->delete($old);
            }
        }

        $user->forceFill(['avatar_url' => null])->save();

        return response()->json(['ok' => true]);
    }
}
