<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\BackofficeAuditLog;
use App\Models\Client;
use App\Models\HelpCenterSpace;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Backoffice\BackofficeAudit;
use App\Services\Backoffice\ClientAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The Clients module (docs/features/backoffice-clients.md, §3–§19).
 *
 * Blade rather than Vue, unlike the Help Center's screens. These are read-mostly administrative
 * tables whose interactivity is a search box and four filters — all of which a form and a query
 * string do honestly, and which then survive a refresh, a bookmark and a paste to a colleague.
 * A client-side app here would reimplement the query in the browser for no behaviour the URL
 * does not already give.
 */
class ClientController extends Controller
{
    /** The eight tabs of §7, in the requirement's order. */
    /**
     * The tabs of the client detail page.
     *
     * The six the requirement lists once a Client became a PERSON (BC-D7). Workspaces became
     * Tenants — the same list under the word the requirement now uses — and Spaces and
     * Subscription are gone from the top level: spaces are a column INSIDE a tenant row, which
     * is where they belong when the record is a person rather than one company's workspace.
     */
    private const TABS = [
        'overview' => 'Overview',
        'tenants' => 'Tenants',
        'applications' => 'Applications',
        'usage' => 'Usage',
        'users' => 'Users',
        'activity' => 'Activity',
    ];

    public function __construct(
        private readonly ClientAdmin $admin,
        private readonly BackofficeAudit $audit,
    ) {}

    /** GET /backoffice/clients — one row per unique email (BC-D7). */
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'application' => (string) $request->query('application', ''),
            'created' => (string) $request->query('created', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $clients = Client::query()
            ->search($filters['q'])
            ->status($filters['status'])
            ->application($filters['application'])
            ->createdWithin($filters['created'], $filters['from'] ?: null, $filters['to'] ?: null)
            ->with(['user', 'membershipRows.workspace'])
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        /*
         * The row payload is built here rather than in the view, because each row needs its
         * TENANTS expanded underneath it and the template should not be running queries inside
         * a loop to get them. `membershipRows.workspace` above is what makes this free.
         */
        $rows = $clients->map(function (Client $client) {
            $memberships = $client->membershipRows;

            return [
                'model' => $client,
                'name' => $client->displayName(),
                'email' => $client->email(),
                'tenants' => $memberships->map(fn ($m) => [
                    'id' => $m->workspace_id,
                    'name' => $m->workspace->name ?? 'Unknown tenant',
                    'role' => ucfirst((string) $m->role),
                    'status' => (string) $m->status,
                ])->values()->all(),
                'applications' => collect($client->applications())->filter->enabled->pluck('label')->implode(', '),
                'last_active' => $client->lastActiveAt()?->format('M j, Y'),
            ];
        });

        return view('backoffice.clients.index', [
            'clients' => $clients,
            'rows' => $rows,
            'filters' => $filters,
            'statuses' => Client::STATUSES,
            'applications' => collect((array) config('workspace.apps'))
                ->map(fn (array $a, string $k) => ['key' => $k, 'label' => $a['label']])
                ->values()->all(),
        ]);
    }

    /** GET /backoffice/clients/{client} — the detail page (§6–§15). */
    public function show(Request $request, Client $client): View
    {
        $tab = (string) $request->query('tab', 'overview');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'overview';

        $client->load(['user', 'membershipRows.workspace']);

        // §24 lists CLIENT_VIEWED as an auditable event. Recorded on the detail page rather than
        // the list: opening one company's record is the act worth being able to account for.
        $this->audit->record(
            BackofficeAuditLog::CLIENT_VIEWED,
            $client->email(),
            Auth::guard('backoffice')->user(),
            meta: ['client_id' => $client->id, 'client_code' => $client->code, 'tab' => $tab],
        );

        return view('backoffice.clients.show', [
            'client' => $client,
            'tab' => $tab,
            'tabs' => self::TABS,
            'data' => $this->tabData($client, $tab),
        ]);
    }

    /**
     * Only the open tab's data is loaded.
     *
     * The Activity feed and the Users table are the expensive ones, and a page that loaded all
     * eight tabs to render one would pay for seven nobody is looking at.
     *
     * @return array<string, mixed>
     */
    private function tabData(Client $client, string $tab): array
    {
        $tenants = $client->tenants();
        $tenantIds = $tenants->pluck('id');

        return match ($tab) {
            /*
             * The Tenants tab (§ "Tenants Tab") — every tenant this person belongs to, with the
             * role that membership gives them. This is the answer to the reported bug: three
             * tenants are three ROWS HERE, not three clients in the list.
             */
            'tenants' => ['tenants' => $client->membershipRows->map(function ($m) use ($tenants) {
                $tenant = $tenants->firstWhere('id', $m->workspace_id);

                return [
                    'id' => $m->workspace_id,
                    'name' => $tenant->name ?? 'Unknown tenant',
                    'role' => ucfirst((string) $m->role),
                    'status' => (string) $m->status,
                    'applications' => $tenant
                        ? collect(['projects' => 'Projects', 'wiki' => 'Wiki', 'helpdesk' => 'Help Center'])
                            ->filter(function ($label, $key) use ($tenant) {
                                $column = Client::appColumn($key);

                                return $column === null || (bool) ($tenant->workspaceSettings->{$column} ?? false);
                            })->implode(', ')
                        : '—',
                    'spaces' => HelpCenterSpace::query()->withoutGlobalScopes()
                        ->where('tenant_id', $m->workspace_id)->count(),
                    'created' => $tenant?->created_at?->format('M j, Y'),
                    'joined' => $m->joined_at?->format('M j, Y'),
                ];
            })->values()->all()],

            'applications' => ['applications' => $client->applications()],

            'usage' => ['usage' => $client->usage()],

            /*
             * Users = the people this person shares tenants with. A Users tab on somebody's own
             * record that listed only them would be a one-row table.
             */
            'users' => ['users' => $client->colleagues()
                ->orderByDesc('joined_at')
                ->limit(200)
                ->get()
                ->map(fn (WorkspaceMembership $m) => [
                    'name' => $m->user?->name ?? '—',
                    'email' => $m->user?->email,
                    'workspace' => $m->workspace?->name,
                    'role' => ucfirst((string) $m->role),
                    'status' => (string) $m->status,
                    'last_active' => $m->joined_at?->format('M j, Y'),
                ])->all()],

            'activity' => ['activities' => $client->activities()->limit(100)->get()->map->toPayload()->all()],

            // Overview reads the client's own columns and the user behind it.
            default => [],
        };
    }

    /** POST /backoffice/clients/{client}/reset-password (§16). */
    public function resetPassword(Client $client): RedirectResponse
    {
        $sent = $this->admin->sendPasswordReset($client, Auth::guard('backoffice')->user());

        return $sent
            ? back()->with('status', 'Reset link sent to '.$client->email().'.')
            : back()->withErrors(['client' => 'This client has no user account to send a reset link to.']);
    }

    /** POST /backoffice/clients/{client}/disable and /enable (§17). */
    public function disable(Client $client): RedirectResponse
    {
        $this->admin->disable($client, Auth::guard('backoffice')->user());

        return back()->with('status', $client->displayName().' has been disabled across every tenant.');
    }

    public function enable(Client $client): RedirectResponse
    {
        $this->admin->enable($client, Auth::guard('backoffice')->user());

        return back()->with('status', $client->displayName().' has been re-enabled.');
    }

    /**
     * POST /backoffice/clients/{client}/delete (§18–§19).
     *
     * The typed name is checked HERE, not only in the modal. A confirmation that lives only in
     * the browser is a confirmation an unlucky script can skip.
     */
    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $typed = trim((string) $request->input('confirm_name'));

        if (mb_strtolower($typed) !== mb_strtolower($client->displayName())) {
            return back()->withErrors(['confirm_name' => 'The name did not match. Nothing has been deleted.']);
        }

        $this->admin->requestDeletion($client, Auth::guard('backoffice')->user());

        return redirect()->route('backoffice.clients.index')->with('status',
            $client->displayName().' is now pending deletion. Its data is kept until '
            .$client->purgeableAt()?->format('M j, Y').' and can be restored until then.');
    }

    /** POST /backoffice/clients/{client}/restore (§19). */
    public function restore(Client $client): RedirectResponse
    {
        $this->admin->restore($client, Auth::guard('backoffice')->user());

        return back()->with('status', $client->displayName().' has been restored.');
    }

    /* ===== tenant-scoped actions (§ "Client Actions") =================================
       Separate endpoints from the global ones above, deliberately: the requirement wants an
       administrator who means to change one workspace to be unable to reach the switch that
       closes all of them. `membershipFor()` also refuses a membership that does not belong to
       the client in the URL. */

    public function tenant(Client $client, string $tenant): View
    {
        $membership = $this->membershipFor($client, $tenant);
        $workspace = $membership->workspace;

        return view('backoffice.clients.tenant', [
            'client' => $client,
            'membership' => $membership,
            'workspace' => $workspace,
            // The workspace's own role vocabulary — one list, so the Back Office cannot offer a
            // role the product does not have.
            'roles' => (array) config('workspace.roles', []),
            'spaces' => HelpCenterSpace::query()->withoutGlobalScopes()
                ->where('tenant_id', $workspace->id)->count(),
            'members' => WorkspaceMembership::query()->where('workspace_id', $workspace->id)->count(),
        ]);
    }

    public function tenantAccess(Request $request, Client $client, string $tenant): RedirectResponse
    {
        $membership = $this->membershipFor($client, $tenant);
        $actor = Auth::guard('backoffice')->user();

        $request->boolean('enable')
            ? $this->admin->enableTenantAccess($client, $membership, $actor)
            : $this->admin->disableTenantAccess($client, $membership, $actor);

        return back()->with('status', 'Tenant access updated for this tenant only.');
    }

    public function tenantRole(Request $request, Client $client, string $tenant): RedirectResponse
    {
        $membership = $this->membershipFor($client, $tenant);

        $data = $request->validate(['role' => ['required', 'string', 'max:40']]);

        $this->admin->changeTenantRole($client, $membership, $data['role'], Auth::guard('backoffice')->user());

        return back()->with('status', 'Role updated for this tenant only.');
    }

    public function tenantRemove(Client $client, string $tenant): RedirectResponse
    {
        $membership = $this->membershipFor($client, $tenant);
        $name = $membership->workspace->name ?? 'the tenant';

        $this->admin->removeFromTenant($client, $membership, Auth::guard('backoffice')->user());

        return redirect()->route('backoffice.clients.show', [$client, 'tab' => 'tenants'])
            ->with('status', 'Removed from '.$name.'. Their other tenants are untouched.');
    }

    /** The membership, checked to belong to THIS client — or a 404. */
    private function membershipFor(Client $client, string $tenantId): WorkspaceMembership
    {
        $membership = WorkspaceMembership::query()
            ->where('user_id', $client->user_id)
            ->where('workspace_id', $tenantId)
            ->with('workspace')
            ->first();

        abort_if($membership === null || $membership->workspace === null, 404);

        return $membership;
    }
}
