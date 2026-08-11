<?php

namespace App\Http\Controllers\Invitation;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\WorkspaceInvitation;
use App\Services\AuthCodeService;
use App\Services\WorkspaceInvitationAccepter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The public invitation link — `/invite/{token}` (invite spec §18–§20, §52–§54, §62–§64).
 *
 * Open to guests and signed-in users alike: the same URL has to work for someone who has no
 * account yet, someone who is signed in as the invited person, and someone signed in as
 * somebody else. Which of those it is decides what the page offers, and every one of them is
 * re-checked server-side on submit — the page never carries the decision (§80).
 *
 * The route parameter is the RAW token; only its hash is stored, so an attacker with database
 * access still cannot reconstruct a working link (§13/§79).
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly AuthCodeService $codes,
        private readonly WorkspaceInvitationAccepter $accepter,
    ) {}

    /** GET /invite/{token} — the invitation landing page (§19/§20). */
    public function show(string $token): View|RedirectResponse
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        // Unknown token: same screen as a revoked one, so probing cannot distinguish
        // "never existed" from "cancelled" (§73).
        if (! $invitation) {
            return $this->unavailable('unknown');
        }

        $invitation->markExpiredIfLapsed();
        $workspace = $invitation->workspace;

        if (! $workspace) {
            return $this->unavailable('workspace_unavailable');
        }

        if (! $invitation->isAcceptable()) {
            return $this->unavailable($this->stateOf($invitation), $invitation);
        }

        $user = Auth::user();

        // §53/§54: signed in as somebody else — say who the invitation is for and stop there.
        if ($user && strtolower((string) $user->email) !== $invitation->email) {
            return view('invitations.mismatch', [
                'invitedEmail' => $invitation->email,
                'currentEmail' => $user->email,
                'workspaceName' => $workspace->name,
            ]);
        }

        return view('invitations.show', [
            'token' => $token,
            'workspace' => $workspace,
            'invitation' => $invitation,
            'inviterName' => $invitation->inviter?->displayName() ?? 'A workspace admin',
            'roleLabel' => config('workspace.roles')[$invitation->role] ?? ucfirst($invitation->role),
            'signedIn' => $user !== null,
        ]);
    }

    /**
     * POST /invite/{token}/start — a guest accepts (§21/§52).
     *
     * Both new and existing people come through here: this app's sign-up and sign-in are the
     * same 6-digit email code, so one action covers "create an account" and "sign in", and
     * `VerifyCodeController` resumes an existing account rather than duplicating it (§52).
     * The address is taken from the invitation, never from the request, so the invitation
     * cannot be redirected to another mailbox (§22).
     */
    public function start(Request $request, string $token): RedirectResponse
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isAcceptable() || ! $invitation->workspace) {
            return redirect()->route('invitations.show', ['token' => $token]);
        }

        if (Auth::check()) {
            return redirect()->route('invitations.show', ['token' => $token]);
        }

        $this->codes->issue($invitation->email, EmailVerificationCode::PURPOSE_SIGNUP);

        $request->session()->put('pending_email', $invitation->email);
        $request->session()->put('login_purpose', EmailVerificationCode::PURPOSE_SIGNUP);
        $request->session()->put('pending_terms_accepted', true);
        // Remembered so that finishing onboarding lands on this invitation rather than on
        // workspace creation, even for someone who already belongs to other workspaces (D-I5).
        $request->session()->put('invitation_token', $token);

        return redirect()->route('auth.verify.show')
            ->with('status', 'We sent a 6-digit code to '.$invitation->email.'.');
    }

    /**
     * POST /invite/{token}/accept — a signed-in, matching user accepts (§53/§55).
     * No onboarding is repeated: an existing account joins in one step.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        if (! $invitation) {
            return redirect()->route('invitations.show', ['token' => $token]);
        }

        $result = $this->accepter->accept($invitation, Auth::user());

        if (! $result['ok']) {
            return redirect()->route('invitations.show', ['token' => $token])
                ->with('invitation_error', $result['reason']);
        }

        $request->session()->forget('invitation_token');

        return redirect()->route('invitations.joined');
    }

    /** Which "cannot be used" screen a non-acceptable invitation gets (§62–§64). */
    private function stateOf(WorkspaceInvitation $invitation): string
    {
        return match (true) {
            $invitation->status === WorkspaceInvitation::STATUS_ACCEPTED => 'accepted',
            $invitation->status === WorkspaceInvitation::STATUS_REVOKED => 'revoked',
            default => 'expired',
        };
    }

    /**
     * An already-accepted link is a special case: if the viewer is signed in and still a
     * member, send them into the workspace instead of showing a dead end (§64).
     */
    private function unavailable(string $state, ?WorkspaceInvitation $invitation = null): View|RedirectResponse
    {
        $user = Auth::user();

        if ($state === 'accepted' && $user && $invitation
            && $user->workspaces()->whereKey($invitation->tenant_id)->exists()) {
            return redirect()->route('welcome');
        }

        return view('invitations.unavailable', ['state' => $state]);
    }
}
