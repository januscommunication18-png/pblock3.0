<?php

namespace App\Http\Requests\HelpCenter\Setup;

use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\EmailAddressGuard;
use App\Services\HelpCenter\SetupDraftStore;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Step 2 — Invite Your Support Group (docs/features/help-center.md, P2 §7, §8; HC-D19).
 *
 * The support group may be EMPTY. A Space Lead setting up alone is a real first run, and
 * requiring a second person would block it.
 */
class TeamStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'members' => ['present', 'array', 'max:100'],
            'members.*.email' => ['required', 'string', 'max:255'],
            'members.*.user_id' => ['nullable', 'integer'],
            'members.*.role' => ['required', 'string'],
            'members.*.department_groups' => ['nullable', 'array'],
            'members.*.department_groups.*' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = Auth::user();
            $workspace = $user?->currentWorkspace;

            if ($workspace === null) {
                return;
            }

            $roles = (array) config('workspace.invite_roles');

            // The groups Step 1 defined are the only ones assignable (P2 §6).
            $groups = array_map(
                'mb_strtolower',
                (array) (app(SetupDraftStore::class)->for($workspace, $user)
                    ->section(SetupDraftStore::SPACE)['department_groups'] ?? []),
            );

            $seen = [];

            foreach ((array) $this->input('members', []) as $i => $row) {
                $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

                if ($email === '') {
                    continue;
                }

                if (! EmailAddressGuard::isRoutable($email)) {
                    $validator->errors()->add("members.$i.email", 'Enter a valid email address.');

                    continue;
                }

                // One row per person. A duplicate is a mistake, not a second membership.
                if (isset($seen[$email])) {
                    $validator->errors()->add("members.$i.email", 'This coworker is already in the list.');

                    continue;
                }

                $seen[$email] = true;

                $role = (string) ($row['role'] ?? '');

                if (! in_array($role, $roles, true)) {
                    $validator->errors()->add("members.$i.role", 'Choose a role for this coworker.');

                    continue;
                }

                /*
                 * The actor must be allowed to hand that role out (HC-D19).
                 *
                 * `WorkspacePolicy::assignRole()` is the same gate Settings → Members uses:
                 * only an owner may create an owner, everybody else may assign strictly below
                 * their own rank. Step 2 is a new door into inviting people, and a new door
                 * into an existing permission must not be a way around it.
                 *
                 * Said HERE so the user gets a message rather than a silent downgrade; the
                 * committer re-checks and falls back to `member` as the backstop.
                 */
                if (! $user->can('assignRole', [$workspace, $role])) {
                    $validator->errors()->add("members.$i.role", 'You cannot invite somebody with that role.');
                }

                foreach ((array) ($row['department_groups'] ?? []) as $g) {
                    if (! in_array(mb_strtolower(trim((string) $g)), $groups, true)) {
                        $validator->errors()->add(
                            "members.$i.department_groups",
                            'Choose department groups defined in step 1.',
                        );

                        break;
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $members = $this->input('members');

        if (! is_array($members)) {
            $this->merge(['members' => []]);

            return;
        }

        $this->merge([
            'members' => array_values(array_map(function ($row) {
                $row = (array) $row;

                return [
                    'user_id' => $row['user_id'] ?? null,
                    'email' => mb_strtolower(trim((string) ($row['email'] ?? ''))),
                    'role' => trim((string) ($row['role'] ?? '')),
                    'department_groups' => HelpCenterSpace::normalizeTypes((array) ($row['department_groups'] ?? [])),
                ];
            }, $members)),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'members.*.email.required' => 'Choose a coworker or enter an email address.',
            'members.*.role.required' => 'Choose a role for this coworker.',
        ];
    }
}
