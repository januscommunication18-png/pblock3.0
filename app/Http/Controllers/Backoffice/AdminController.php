<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\BackofficeAuditLog;
use App\Models\BackofficeUser;
use App\Services\Backoffice\BackofficeAudit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Managing Back Office users (docs/features/backoffice-auth.md, §8).
 *
 * Built even though the other §12 modules are not (BO-D8), because "manage Back Office users"
 * and the last-Super-Admin invariant are SECURITY rules rather than a module — the boundary is
 * not finished without a way to add and remove the people behind it.
 *
 * Every method is Super-Admin-only, checked here AND in the route middleware. The invariant
 * itself lives on the model (BO-D6), so it holds for any future caller too.
 */
class AdminController extends Controller
{
    public function __construct(private readonly BackofficeAudit $audit) {}

    /** GET /backoffice/admins */
    public function index(): View
    {
        return view('backoffice.admins', [
            'admins' => BackofficeUser::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map->toPayload()
                ->all(),
            'roles' => collect(BackofficeUser::ROLES)
                ->map(fn (string $r) => ['value' => $r, 'label' => BackofficeUser::roleLabel($r)])
                ->all(),
        ]);
    }

    /**
     * POST /backoffice/admins
     *
     * The new account is created WITHOUT a password (BO-D5) and is sent a reset link. Nobody —
     * not even the Super Admin creating them — ever chooses somebody else's password, so there
     * is no moment where a credential exists that two people know.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = Auth::guard('backoffice')->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('backoffice_users', 'email')],
            'role' => ['required', Rule::in(BackofficeUser::ROLES)],
        ]);

        $user = BackofficeUser::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => true,
            'password_hash' => null,
            'created_by' => $actor?->id,
        ]);

        Password::broker('backoffice_users')->sendResetLink(['email' => $user->email]);

        $this->audit->record(BackofficeAuditLog::USER_CREATED, $user->email, $actor, meta: [
            'created_id' => $user->id, 'role' => $user->role,
        ]);

        // A second, distinct event for the role that can do everything — the requirement lists
        // it separately, and an audit reader scanning for "who gained the keys" should not have
        // to filter USER_CREATED by a meta field.
        if ($user->role === BackofficeUser::ROLE_SUPER_ADMIN) {
            $this->audit->record(BackofficeAuditLog::SUPER_ADMIN_CREATED, $user->email, $actor);
        }

        return back()->with('status', $user->email.' has been added and sent a link to set their password.');
    }

    /**
     * PATCH /backoffice/admins/{admin}
     *
     * Role changes and enable/disable, both of which can hit the last-Super-Admin invariant. The
     * model throws; this catches and reports, because the person needs to be told what to do
     * about it rather than shown a 500.
     */
    public function update(Request $request, BackofficeUser $admin): RedirectResponse
    {
        $actor = Auth::guard('backoffice')->user();

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(BackofficeUser::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            if (array_key_exists('role', $data) && $data['role'] !== $admin->role) {
                // Downgrading the last Super Admin is the same lockout as deleting them.
                if ($data['role'] !== BackofficeUser::ROLE_SUPER_ADMIN) {
                    $admin->guardLastSuperAdmin('change the role of');
                }

                $before = $admin->role;
                $admin->forceFill(['role' => $data['role']])->save();

                $this->audit->record(BackofficeAuditLog::ROLE_CHANGED, $admin->email, $actor, meta: [
                    'from' => $before, 'to' => $data['role'], 'target_id' => $admin->id,
                ]);

                if ($data['role'] === BackofficeUser::ROLE_SUPER_ADMIN) {
                    $this->audit->record(BackofficeAuditLog::SUPER_ADMIN_CREATED, $admin->email, $actor);
                }
            }

            if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $admin->is_active) {
                if (! $data['is_active']) {
                    $admin->guardLastSuperAdmin('disable');
                }

                $admin->forceFill(['is_active' => (bool) $data['is_active']])->save();

                if (! $data['is_active']) {
                    $this->audit->record(BackofficeAuditLog::USER_DISABLED, $admin->email, $actor, meta: [
                        'target_id' => $admin->id,
                    ]);
                }
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['admin' => $e->getMessage()]);
        }

        return back()->with('status', 'Administrator updated.');
    }
}
