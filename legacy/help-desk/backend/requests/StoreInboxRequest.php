<?php

namespace App\Http\Requests\HelpDesk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or configuring an inbox (docs/features/help-desk.md, FR-2.1/2.3/2.5).
 *
 * There is NO `inbound_address` rule, and its absence is the point: the address an inbox
 * receives at is generated and read-only (Inbound Email requirements §2), so there is nothing
 * to validate and nothing a request may say about it. What an administrator connects instead is
 * their own customer-facing address, which is StoreEmailAddressRequest.
 *
 * Name uniqueness stays with the database (see InboxController::guardDuplicate): it is scoped to
 * one Help Desk and can lose a race between two administrators, which a pre-flight query cannot.
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
            'name' => ['required', 'string', 'max:80'],

            // Which space owns this inbox (Workspace & Inbox Assignment §7, §11, §12). Checked
            // against THIS Help Desk in the controller, where the Help Desk is known — a rule
            // here could only check that the id exists somewhere.
            'help_desk_space_id' => ['nullable', 'integer'],

            'outbound_from_name' => ['nullable', 'string', 'max:120'],
            'outbound_from_address' => ['nullable', 'string', 'email', 'max:190'],

            // Checked against THIS Help Desk's members in the controller, where the Help Desk is
            // known — a rule here could only check that the id exists somewhere.
            'default_assignee_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('outbound_from_address')) {
            $value = strtolower(trim((string) $this->input('outbound_from_address')));

            // "" is not an address: the column is nullable and an empty string would be stored
            // as a configured-but-blank sender.
            $this->merge(['outbound_from_address' => $value === '' ? null : $value]);
        }
    }
}
