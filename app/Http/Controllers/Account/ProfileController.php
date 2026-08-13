<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The account modal's Profile tab (Account §1).
 *
 * Everything here acts on the SIGNED-IN user and takes no user id from the request. There is
 * no `{user}` to tamper with, so "may I edit this profile?" cannot be got wrong — it is not a
 * question this controller ever asks.
 *
 * Images are stored on the PRIVATE disk and streamed back through `image()`, the same shape
 * WorkItemMediaController uses and for the same reason (CLAUDE.md §7): a file on the public
 * disk is a URL that works for anyone who receives it, regardless of workspace. An avatar is
 * less sensitive than a project attachment, but it is still a face and a name, and the cheap
 * consistent thing is not to hand it out.
 */
class ProfileController extends Controller
{
    /** Where each image lives on the user's row. The only two kinds that exist. */
    private const KINDS = ['avatar' => 'avatar_url', 'cover' => 'cover_url'];

    /** PATCH /account/profile — the Profile tab's Save changes. */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill($request->validated());
        // `full_name` is what displayName(), initial() and every row avatar already read, so
        // it is kept in step here rather than teaching each of them about the two-field split.
        $user->full_name = $user->composeFullName();
        $user->save();

        return response()->json(['ok' => true, 'profile' => $this->payload($user->fresh()), 'message' => 'Profile updated.']);
    }

    /**
     * POST /account/profile/image — the cover and avatar uploads.
     *
     * The previous file is deleted after the new one is stored, not before: a failed upload
     * that had already removed the old image would leave the user with neither.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(self::KINDS))],
            'image' => [
                'required', 'file', 'image',
                'mimes:'.implode(',', config('projects.media.mimes')),
                'max:'.(int) config('projects.media.max_kb'),
            ],
        ]);

        $column = self::KINDS[$data['kind']];
        // The RAW column: the accessor answers with the serving URL, and Storage wants the path.
        $previous = $user->getRawOriginal($column);

        $path = $request->file('image')->store("account/{$user->id}", 'local');

        $user->forceFill([$column => $path])->save();

        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json(['ok' => true, 'profile' => $this->payload($user->fresh())]);
    }

    /** DELETE /account/profile/image — take the cover or avatar back off. */
    public function destroyImage(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $kind = (string) $request->input('kind');
        abort_unless(isset(self::KINDS[$kind]), 422);

        $column = self::KINDS[$kind];

        if ($user->getRawOriginal($column)) {
            Storage::disk('local')->delete($user->getRawOriginal($column));
            $user->forceFill([$column => null])->save();
        }

        return response()->json(['ok' => true, 'profile' => $this->payload($user->fresh())]);
    }

    /**
     * GET /account/image/{user}/{kind} — stream a stored profile image.
     *
     * Readable by the owner, and by anyone who shares a workspace with them: an avatar has to
     * work wherever that person appears — a work item row, a member list, a comment — or it is
     * an avatar only they can see. Memberships are central rather than tenant-scoped (D6), so
     * this answers without a tenancy context, which is what lets it run on a bare image route.
     */
    public function image(User $user, string $kind): StreamedResponse
    {
        abort_unless(isset(self::KINDS[$kind]), 404);

        $viewer = Auth::user();
        abort_unless($viewer !== null && $this->maySee($viewer, $user), 404);

        $path = $user->getRawOriginal(self::KINDS[$kind]);
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            // Private, because the response depends on who is asking: a shared cache handing
            // this to the next viewer would be handing it to someone who may not see it.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * The URL for a stored image, stamped with a version.
     *
     * The route itself is stable — `/account/image/{user}/{kind}` names the SLOT, not the file
     * — so uploading a replacement changes the bytes behind an identical URL, and the browser,
     * holding a cached copy, goes on showing the previous photo. The response is deliberately
     * cacheable, so the cache has to be told when it is stale, and the only thing that can tell
     * it is the URL.
     *
     * The stamp is a hash of the stored path, which carries the random filename Laravel gave
     * the upload — so it changes on every replacement and on nothing else. `updated_at` would
     * also work but would bust the image cache every time the user renamed themselves.
     */
    private static function imageUrl(User $user, string $kind): ?string
    {
        // The model's accessor builds this now, so every screen that reads `avatar_url` gets
        // the same URL this modal does — there is no second definition to drift.
        return $user->{self::KINDS[$kind]};
    }

    /** Do these two people share a workspace? Trivially true when they are the same person. */
    private function maySee(User $viewer, User $subject): bool
    {
        if ((int) $viewer->id === (int) $subject->id) {
            return true;
        }

        return WorkspaceMembership::query()
            ->where('user_id', $viewer->id)
            ->where('status', 'active')
            ->whereIn('workspace_id', WorkspaceMembership::query()
                ->where('user_id', $subject->id)
                ->where('status', 'active')
                ->select('workspace_id'))
            ->exists();
    }

    /**
     * What the modal renders from.
     *
     * The stored value is a disk path, never a URL — the client is given the route that serves
     * it, so where the file physically lives stays a server-side detail.
     *
     * @return array<string, mixed>
     */
    public static function payload(User $user): array
    {
        $gradients = array_values(config('projects.cover_gradients', []));

        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'initial' => $user->initial(),
            'name' => $user->displayName(),
            'avatar_url' => self::imageUrl($user, 'avatar'),
            'cover_url' => self::imageUrl($user, 'cover'),
            // The banner falls back to a gradient rather than to empty grey, the same way an
            // uncovered project tile does — so a profile looks deliberate before anyone has
            // uploaded anything.
            'cover_gradient' => $user->cover_gradient ?: ($gradients[0] ?? null),
            'cover_presets' => $gradients,
        ];
    }
}
