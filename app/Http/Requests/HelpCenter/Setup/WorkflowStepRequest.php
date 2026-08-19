<?php

namespace App\Http\Requests\HelpCenter\Setup;

use App\Models\HelpCenterStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 4 — Configure Your Workflow (docs/features/help-center.md, P2 §11–§16).
 *
 * The system constraints of P2 §16 are NOT validated as errors here, because they are not the
 * user's mistakes to correct — the committer normalizes them (`Open → custom → Closed`, Open
 * always Active, Closed always Inactive). What IS validated is the part a person can get wrong:
 * a custom status with no name, a duplicate name, too many of them.
 *
 * Rejecting a reordered payload would mean showing an error for something the UI does not let
 * you do; silently correcting it is right, and the commit is where that happens.
 */
class WorkflowStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'statuses' => ['present', 'array', 'max:'.((int) config('help-center.status_max', 20) + 2)],
            'statuses.*.name' => ['required', 'string', 'max:'.(int) config('help-center.status_max_length', 60)],
            'statuses.*.color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'statuses.*.responsibility' => ['required', 'string'],
            'statuses.*.is_active' => ['boolean'],
            'statuses.*.system_key' => ['nullable', 'string'],
            'statuses.*.default_assignees' => ['nullable', 'array'],
            'statuses.*.default_assignees.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $rows = (array) $this->input('statuses', []);
            $seen = [];
            $custom = 0;

            foreach ($rows as $i => $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $key = $row['system_key'] ?? null;

                if ($key === null) {
                    $custom++;
                }

                if (! in_array($row['responsibility'] ?? null, HelpCenterStatus::responsibilities(), true)) {
                    $validator->errors()->add("statuses.$i.responsibility", 'Choose Creator or Assignee.');
                }

                if ($name === '') {
                    continue;
                }

                // "Status names should be unique within the Space" (P2 §14, §29) — compared
                // case-insensitively, because two statuses differing only in capitals are two
                // rows nobody can tell apart in a dropdown.
                $lower = mb_strtolower($name);

                if (isset($seen[$lower])) {
                    $validator->errors()->add("statuses.$i.name", 'Status names must be unique.');

                    continue;
                }

                $seen[$lower] = true;
            }

            $max = (int) config('help-center.status_max', 20);

            if ($custom > $max) {
                $validator->errors()->add('statuses', "A workflow can have at most {$max} custom statuses.");
            }

            /*
             * Both system rows have to be present.
             *
             * Not something the UI can do — they are undeletable — so this is about a payload
             * that arrived without them. The committer would put them back anyway; failing here
             * means a broken client is told, rather than quietly corrected.
             */
            $keys = array_filter(array_column($rows, 'system_key'));

            foreach ([HelpCenterStatus::SYSTEM_OPEN, HelpCenterStatus::SYSTEM_CLOSED] as $required) {
                if (! in_array($required, $keys, true)) {
                    $validator->errors()->add('statuses', 'The Open and Closed statuses cannot be removed.');

                    break;
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('statuses');

        if (! is_array($rows)) {
            $this->merge(['statuses' => []]);

            return;
        }

        $this->merge([
            'statuses' => array_values(array_map(function ($row) {
                $row = (array) $row;
                $key = $row['system_key'] ?? null;
                $key = ($key === '' ? null : $key);

                return [
                    // The two system names are not the client's to change (P2 §13, §15), so they
                    // are restored here rather than validated — a payload that renamed Open is
                    // corrected, not argued with.
                    'name' => match ($key) {
                        HelpCenterStatus::SYSTEM_OPEN => 'Open',
                        HelpCenterStatus::SYSTEM_CLOSED => 'Closed',
                        default => trim((string) ($row['name'] ?? '')),
                    },
                    'color' => strtolower(trim((string) ($row['color'] ?? ''))),
                    'responsibility' => (string) ($row['responsibility'] ?? HelpCenterStatus::RESPONSIBILITY_ASSIGNEE),
                    'is_active' => match ($key) {
                        HelpCenterStatus::SYSTEM_OPEN => true,
                        HelpCenterStatus::SYSTEM_CLOSED => false,
                        default => (bool) ($row['is_active'] ?? true),
                    },
                    'system_key' => $key,
                    'default_assignees' => array_values(array_unique(array_map(
                        'intval',
                        array_filter((array) ($row['default_assignees'] ?? []), 'is_numeric'),
                    ))),
                ];
            }, $rows)),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'statuses.*.name.required' => 'Give the status a name.',
            'statuses.*.name.max' => 'A status name can be at most :max characters.',
            'statuses.*.color.regex' => 'Choose a colour.',
        ];
    }
}
