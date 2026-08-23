<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterStatus;
use Illuminate\Contracts\Validation\Validator;

/**
 * What a workflow payload must look like, in one place
 * (docs/features/help-center.md, P2 §11–§16, P16).
 *
 * There are two doors onto the same list of statuses now: the setup wizard's step 4, and
 * Settings → Workflow's editor. Both send the same rows and both must apply the same rules —
 * names unique within the Space, at most `status_max` custom ones, both system rows present,
 * Open always Active and Closed always Inactive.
 *
 * Written once here rather than twice in two form requests, because a rule that exists in two
 * places is a rule that will hold in one of them after the next change.
 *
 * NOTE what is validated and what is merely corrected. The system constraints of §16 are not the
 * user's mistakes to fix — a payload that renamed Open, or put Closed second, is normalized, not
 * argued with. What is validated is what a person can actually get wrong.
 */
class WorkflowStatusPayload
{
    /**
     * The field rules, keyed as `statuses.*`.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
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

            /*
             * Only the editor sends these; the wizard's cards do not render them.
             *
             * `nullable` rather than `required` for exactly that reason — a step-4 payload
             * without them is not wrong, it is a first run that deliberately asks less, and
             * `normalize()` fills in the same defaults the committer always used.
             */
            'statuses.*.waiting_on' => ['nullable', 'string'],
            // The System Category (P54). Nullable for the same reason: the wizard's cards send
            // one now, but a payload from an older client is a first run, not a mistake.
            'statuses.*.system_category' => ['nullable', 'string'],
            'statuses.*.is_default' => ['boolean'],
            // Present on rows that already exist; absent on ones just added.
            'statuses.*.id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'statuses.*.name.required' => 'Give the status a name.',
            'statuses.*.name.max' => 'A status name can be at most :max characters.',
            'statuses.*.color.regex' => 'Choose a colour.',
        ];
    }

    /**
     * The rows as they should be stored, whatever arrived.
     *
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function normalize($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(function ($row) {
            $row = (array) $row;
            $key = $row['system_key'] ?? null;
            $key = ($key === '' ? null : $key);

            return [
                'id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null,
                // The two system names are not the client's to change (P2 §13, §15), so they
                // are restored here rather than validated — a payload that renamed Open is
                // corrected, not argued with.
                'name' => match ($key) {
                    HelpCenterStatus::SYSTEM_OPEN => 'Open',
                    HelpCenterStatus::SYSTEM_CLOSED => 'Closed',
                    default => trim((string) ($row['name'] ?? '')),
                },
                'color' => strtolower(trim((string) ($row['color'] ?? ''))),
                'responsibility' => in_array($row['responsibility'] ?? null, HelpCenterStatus::responsibilities(), true)
                    ? (string) $row['responsibility']
                    : HelpCenterStatus::RESPONSIBILITY_ASSIGNEE,
                'waiting_on' => in_array($row['waiting_on'] ?? null, HelpCenterStatus::waitingOptions(), true)
                    ? (string) $row['waiting_on']
                    // The historical defaults: new mail is waiting on an agent, and a closed
                    // conversation is waiting on nobody.
                    : match ($key) {
                        HelpCenterStatus::SYSTEM_CLOSED => HelpCenterStatus::WAITING_NEITHER,
                        default => HelpCenterStatus::WAITING_AGENT,
                    },
                /*
                 * The System Category (P54), CORRECTED rather than argued with — the same
                 * treatment the two system names get above.
                 *
                 * Open and Closed are pinned: they are the workflow's own start and end, the
                 * two rows a Space cannot rename or remove, and letting somebody file Closed
                 * under "Waiting" would break every consumer that trusts the vocabulary. An
                 * unrecognised value on any other row falls back to `active`, which is the
                 * safest wrong answer — it says "an agent owes something", which is what an
                 * unclassified state in a support workflow almost always means.
                 */
                'system_category' => match ($key) {
                    HelpCenterStatus::SYSTEM_OPEN => 'open',
                    HelpCenterStatus::SYSTEM_CLOSED => 'closed',
                    default => HelpCenterStatus::isSystemCategory($row['system_category'] ?? null)
                        ? (string) $row['system_category']
                        : 'active',
                },
                'is_active' => match ($key) {
                    HelpCenterStatus::SYSTEM_OPEN => true,
                    HelpCenterStatus::SYSTEM_CLOSED => false,
                    default => (bool) ($row['is_active'] ?? true),
                },
                // Never taken from a Closed row: a workflow whose starting status is Closed is
                // a Space where every new Request arrives already finished.
                'is_default' => $key !== HelpCenterStatus::SYSTEM_CLOSED && (bool) ($row['is_default'] ?? false),
                'system_key' => $key,
                'default_assignees' => array_values(array_unique(array_map(
                    'intval',
                    array_filter((array) ($row['default_assignees'] ?? []), 'is_numeric'),
                ))),
            ];
        }, $rows));
    }

    /**
     * The part a person can get wrong.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function check(Validator $validator, array $rows): void
    {
        $seen = [];
        $custom = 0;

        foreach ($rows as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if (($row['system_key'] ?? null) === null) {
                $custom++;
            }

            if (! in_array($row['responsibility'] ?? null, HelpCenterStatus::responsibilities(), true)) {
                $validator->errors()->add("statuses.$i.responsibility", 'Choose Creator or Assignee.');
            }

            if ($name === '') {
                continue;
            }

            /*
             * "Status names should be unique within the Space" (P2 §14, §29).
             *
             * Compared case-insensitively, because two statuses differing only in capitals are
             * two rows nobody can tell apart in a dropdown.
             */
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
         * Not something either UI can do — they are undeletable — so this is about a payload
         * that arrived without them. It would be corrected anyway; failing here means a broken
         * client is told, rather than quietly patched up.
         */
        $keys = array_filter(array_column($rows, 'system_key'));

        foreach ([HelpCenterStatus::SYSTEM_OPEN, HelpCenterStatus::SYSTEM_CLOSED] as $required) {
            if (! in_array($required, $keys, true)) {
                $validator->errors()->add('statuses', 'The Open and Closed statuses cannot be removed.');

                break;
            }
        }
    }

    /**
     * The rows in workflow order, each with the position it should hold.
     *
     * `Open → custom → Closed` is a system constraint, so a payload that tried to put Closed
     * second is corrected here rather than obeyed. A list that lost a system row gets the
     * default one back: every Space has an Open and a Closed, and that is not the client's to
     * decide.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function ordered(array $rows): array
    {
        $open = null;
        $closed = null;
        $custom = [];

        foreach ($rows as $row) {
            $key = $row['system_key'] ?? null;

            if ($key === HelpCenterStatus::SYSTEM_OPEN) {
                $open = $row;
            } elseif ($key === HelpCenterStatus::SYSTEM_CLOSED) {
                $closed = $row;
            } else {
                $custom[] = $row;
            }
        }

        $defaults = collect(HelpCenterStatus::systemDefaults())->keyBy('system_key');
        $open ??= self::normalize([$defaults[HelpCenterStatus::SYSTEM_OPEN]])[0];
        $closed ??= self::normalize([$defaults[HelpCenterStatus::SYSTEM_CLOSED]])[0];

        $ordered = [$open + ['position' => HelpCenterStatus::POSITION_OPEN]];
        $position = 1;

        foreach ($custom as $row) {
            $ordered[] = $row + ['position' => $position++];
        }

        $ordered[] = $closed + ['position' => HelpCenterStatus::POSITION_CLOSED];

        /*
         * Exactly one starting status, and Open when nobody chose (P9).
         *
         * `is_default` is a property of the WORKFLOW, not of a row, so it is decided over the
         * whole list — two rows claiming it is a Space that cannot say where a Request opens.
         */
        $chosen = null;

        foreach ($ordered as $i => $row) {
            if ($row['is_default'] && $chosen === null) {
                $chosen = $i;
            }

            $ordered[$i]['is_default'] = false;
        }

        $ordered[$chosen ?? 0]['is_default'] = true;

        return $ordered;
    }
}
