<?php

namespace App\Http\Requests\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Services\HelpCenter\EmailAddressGuard;
use App\Services\HelpCenter\HelpCenterOnboarding;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Creating an Inbox, with the addresses collected alongside it
 * (docs/features/help-center.md §5, §6, §7).
 *
 * Step 2 of the wizard gathers a name AND a list of addresses before anything is saved — §6's
 * table shows rows with a Remove action, so they exist on screen before they exist in the
 * database. Both arrive in one submission and are validated together, so a rejected address
 * cannot leave a half-built Inbox behind.
 */
class StoreInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],

            // Optional: an Inbox with no addresses yet is a legitimate thing to create — §6's
            // section is how you connect one, not a precondition for having one.
            'addresses' => ['sometimes', 'array', 'max:25'],
            'addresses.*.email' => ['required', 'email:rfc', 'max:255'],
            'addresses.*.name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $spaceId = $this->spaceId();
            $name = trim((string) $this->input('name'));

            /*
             * "Must be unique within the Space" (§5) — and only within it. Two Spaces may each
             * run a "General Support", which is most of the point of having Spaces.
             */
            if ($spaceId !== null && $name !== '' && mb_strlen($name) <= 100) {
                $exists = HelpCenterInbox::query()
                    ->where('help_center_space_id', $spaceId)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'This Space already has an Inbox with that name.');
                }
            }

            /*
             * Each address, against the rest of the workspace (§7) and against the rest of this
             * submission.
             *
             * The database's unique index would catch both, but only by failing the whole
             * insert with an error nobody can act on. Caught here, each one names its row.
             */
            $workspace = Auth::user()?->currentWorkspace;
            $guard = app(EmailAddressGuard::class);
            $seen = [];

            foreach ((array) $this->input('addresses', []) as $i => $address) {
                $email = mb_strtolower(trim((string) ($address['email'] ?? '')));

                if ($email === '') {
                    continue;
                }

                if (isset($seen[$email])) {
                    $validator->errors()->add("addresses.$i.email", 'This address is already in the list.');

                    continue;
                }

                $seen[$email] = true;

                // A dotless domain is a legal address the internet cannot reach — see
                // EmailAddressGuard::isRoutable().
                if (! EmailAddressGuard::isRoutable($email)) {
                    $validator->errors()->add("addresses.$i.email", 'Enter a valid email address.');

                    continue;
                }

                if ($workspace !== null && $guard->isTaken($workspace, $email)) {
                    $validator->errors()->add("addresses.$i.email", EmailAddressGuard::TAKEN_MESSAGE);
                }
            }
        });
    }

    /**
     * Which Space this Inbox is going into.
     *
     * Two routes reach here and they name it differently. The Inboxes screen puts it in the URL
     * (`/spaces/{space}/inboxes`); the WIZARD does not — at step 2 there is exactly one Space
     * and asking the user to identify it would be asking them to repeat step 1. So the wizard's
     * Space is resolved the same way the controller resolves it, through HelpCenterOnboarding.
     *
     * Getting this wrong is silent rather than loud: a null here does not fail validation, it
     * SKIPS the uniqueness check, and the duplicate is caught two layers down by the database
     * as a 500. Hence one method, used by both paths.
     */
    private function spaceId(): ?int
    {
        $space = $this->route('space');

        if ($space !== null) {
            return (int) (is_object($space) ? $space->id : $space);
        }

        return app(HelpCenterOnboarding::class)->space()?->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the Inbox a name.',
            'name.max' => 'An Inbox name can be at most 100 characters.',
            'addresses.*.email.required' => 'Enter an email address.',
            'addresses.*.email.email' => 'Enter a valid email address.',
        ];
    }
}
