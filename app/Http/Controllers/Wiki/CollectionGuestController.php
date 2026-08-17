<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wiki\StoreCollectionGuestRequest;
use App\Mail\WikiGuestInvitationMail;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGuest;
use App\Services\WorkspaceApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * External members of a collection (docs/features/wiki-external-guests.md).
 *
 * Behind `manageableBy`, the same gate as publishing and managing access — putting workspace
 * content in front of somebody outside the workspace is the heaviest thing this screen does, and
 * it does not go behind the looser "can edit pages" rule.
 */
class CollectionGuestController extends Controller
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /** POST /wiki/collections/{collection}/guests — invite somebody, or resend to them. */
    public function store(StoreCollectionGuestRequest $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $data = $request->validated();

        /*
         * Re-inviting an address UPDATES the row and issues a fresh token rather than making a
         * second one — the rule re-inviting a workspace member already follows. It also means
         * the old email stops working, which is how somebody cuts off a forwarded link without
         * removing the person.
         */
        $guest = WikiCollectionGuest::query()
            ->where('wiki_collection_id', $collection->id)
            ->where('email', $data['email'])
            ->first()
            ?? new WikiCollectionGuest([
                'tenant_id' => $collection->tenant_id,
                'wiki_collection_id' => $collection->id,
                'invited_by' => Auth::id(),
            ]);

        $raw = $this->send($guest, $collection, $data);

        return response()->json([
            'ok' => true,
            'guests' => $this->guests($collection),
            'message' => $raw ? 'Invitation sent.' : 'Invitation sent.',
        ]);
    }

    /** POST /wiki/collections/{collection}/guests/{guest}/resend — a new link, and the old one dies. */
    public function resend(WikiCollection $collection, WikiCollectionGuest $guest): JsonResponse
    {
        $this->guard($collection);
        $this->own($collection, $guest);

        $this->send($guest, $collection, [
            'name' => $guest->name,
            'email' => $guest->email,
            'login_method' => $guest->login_method,
        ]);

        return response()->json([
            'ok' => true,
            'guests' => $this->guests($collection),
            'message' => 'A new link has been sent. The previous one no longer works.',
        ]);
    }

    /**
     * DELETE /wiki/collections/{collection}/guests/{guest}
     *
     * The row IS the access, so removing it is the whole of revocation — the link and any open
     * session stop working on the next request (WG-5).
     */
    public function destroy(WikiCollection $collection, WikiCollectionGuest $guest): JsonResponse
    {
        $this->guard($collection);
        $this->own($collection, $guest);

        $email = $guest->email;
        $guest->delete();

        Log::info('wiki.guest.removed', [
            'collection' => $collection->id, 'email' => $email, 'by' => Auth::id(),
        ]);

        return response()->json([
            'ok' => true,
            'guests' => $this->guests($collection),
            'message' => 'External member removed. Their link no longer works.',
        ]);
    }

    /**
     * Mint a token, save, and email the link.
     *
     * The raw token is returned to nobody and stored nowhere — it exists between here and the
     * mailable, and after that only its hash survives.
     *
     * @param  array<string, mixed>  $data
     */
    private function send(WikiCollectionGuest $guest, WikiCollection $collection, array $data): string
    {
        $raw = WikiCollectionGuest::newToken();

        $guest->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'login_method' => $data['login_method'],
            'token' => WikiCollectionGuest::hashToken($raw),
            'invited_at' => now(),
        ])->save();

        $inviter = Auth::user();

        Mail::to($guest->email)->send(new WikiGuestInvitationMail(
            guestName: $guest->name,
            workspaceName: $inviter->currentWorkspace->name,
            inviterName: $inviter->full_name ?: $inviter->email,
            collectionName: $collection->name,
            openUrl: route('wiki.guest', ['token' => $raw]),
            workspaceLogoUrl: $inviter->currentWorkspace->logo_url,
        ));

        // The address and the collection, never the token.
        Log::info('wiki.guest.invited', [
            'collection' => $collection->id, 'email' => $guest->email, 'by' => Auth::id(),
        ]);

        return $raw;
    }

    /** @return array<int, array<string, mixed>> */
    private function guests(WikiCollection $collection): array
    {
        return WikiCollectionGuest::query()
            ->where('wiki_collection_id', $collection->id)
            ->orderBy('name')
            ->get()
            ->map(fn (WikiCollectionGuest $g) => $g->toCard())
            ->all();
    }

    private function own(WikiCollection $collection, WikiCollectionGuest $guest): void
    {
        abort_unless((int) $guest->wiki_collection_id === (int) $collection->id, 404);
    }

    /** Deciding who may read is not an editorial act — see CollectionController::canManage(). */
    private function guard(WikiCollection $collection): void
    {
        $user = Auth::user();

        abort_unless($this->apps->isEnabled($user->currentWorkspace, 'wiki'), 404);
        abort_unless($collection->manageableBy($user), 403);
    }
}
