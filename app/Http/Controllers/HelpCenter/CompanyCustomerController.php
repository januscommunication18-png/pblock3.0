<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterCompany;
use App\Models\HelpCenterCustomer;
use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\HelpCenterNavigation;
use App\Services\HelpCenter\HelpCenterOnboarding;
use App\Services\HelpCenter\Metadata\CompanyCustomerDirectory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The Company & Customer management area (docs/features/help-center.md, P75 §11–§13).
 *
 * Reached from the Help Desk navigation, after Spam. One screen with two tabs and two profile
 * views behind them, rather than four routes: Customers and Companies are two views of one
 * dataset, and a person moving between them is filtering, not navigating.
 *
 * The whole area 404s when no Space runs the feature (HC-D57). Not a redirect: the nav does not
 * offer the link in that state, so arriving here means a typed URL or a stale bookmark, and a
 * silent bounce to Overview hides which of those it was.
 */
class CompanyCustomerController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(
        private readonly HelpCenterOnboarding $onboarding,
        private readonly CompanyCustomerDirectory $directory,
    ) {}

    /** GET /help-center/company-customer */
    public function index(Request $request, HelpCenterNavigation $nav): View|RedirectResponse
    {
        $workspace = $this->helpCenterWorkspace();

        if (! $this->onboarding->isComplete()) {
            return redirect()->route('help-center.setup');
        }

        abort_if($this->directory->enabledSpaces()->isEmpty(), 404);

        $tab = $request->query('tab') === 'companies' ? 'companies' : 'customers';

        return view('help-center.company-customer', [
            'workspace' => $workspace,
            'section' => 'company-customer',
            'bootstrap' => [
                'tab' => $tab,
                'canManage' => $this->canManage(),
                'customers' => $this->directory->customers(),
                'companies' => $this->directory->companies(),
                'endpoints' => [
                    'index' => route('help-center.company-customer'),
                    'search' => route('help-center.company-customer.search'),
                    'customer' => route('help-center.company-customer.customer', ['customer' => 0]),
                    'company' => route('help-center.company-customer.company', ['company' => 0]),
                ],
            ],
        ]);
    }

    /**
     * GET /help-center/company-customer/search
     *
     * One endpoint for both tabs, because the two lists are the same query against two tables
     * and the client already knows which one it is looking at.
     */
    public function search(Request $request): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_if($this->directory->enabledSpaces()->isEmpty(), 404);

        $term = (string) $request->query('q', '');

        return response()->json([
            'ok' => true,
            'customers' => $this->directory->customers($term),
            'companies' => $this->directory->companies($term),
        ]);
    }

    /** GET /help-center/company-customer/customers/{customer} — the Customer profile (§12). */
    public function customer(HelpCenterCustomer $customer, HelpCenterNavigation $nav): View
    {
        $workspace = $this->helpCenterWorkspace();
        abort_if($this->directory->enabledSpaces()->isEmpty(), 404);

        return view('help-center.company-customer-profile', [
            'workspace' => $workspace,
            'section' => 'company-customer',
            'title' => $customer->displayName(),
            'bootstrap' => $this->directory->customerProfile($customer) + [
                'kind' => 'customer',
                'canManage' => $this->canManage(),
                'backUrl' => route('help-center.company-customer'),
                'endpoint' => route('help-center.customers.update', ['customer' => $customer->id]),
                'companyUrl' => $customer->help_center_company_id === null ? null : route(
                    'help-center.company-customer.company',
                    ['company' => $customer->help_center_company_id],
                ),
            ],
        ]);
    }

    /** GET /help-center/company-customer/companies/{company} — the Company profile (§13). */
    public function company(HelpCenterCompany $company, HelpCenterNavigation $nav): View
    {
        $workspace = $this->helpCenterWorkspace();
        abort_if($this->directory->enabledSpaces()->isEmpty(), 404);

        return view('help-center.company-customer-profile', [
            'workspace' => $workspace,
            'section' => 'company-customer',
            'title' => $company->displayName(),
            'bootstrap' => $this->directory->companyProfile($company) + [
                'kind' => 'company',
                'canManage' => $this->canManage(),
                'backUrl' => route('help-center.company-customer', ['tab' => 'companies']),
                'endpoint' => route('help-center.companies.update', ['company' => $company->id]),
                'customerUrl' => route('help-center.company-customer.customer', ['customer' => 0]),
            ],
        ]);
    }

    /**
     * May this person edit these records?
     *
     * Whoever may manage ANY Space — the same rule `CustomerController` already applies, and for
     * the same reason: the records belong to the workspace rather than to a Space, so there is
     * no single Space to check against, and checking whichever Space a ticket happens to be in
     * would let the same person edit through one and not another.
     */
    private function canManage(): bool
    {
        return HelpCenterSpace::query()->get()
            ->contains(fn (HelpCenterSpace $space) => Auth::user()->can('update', $space));
    }
}
