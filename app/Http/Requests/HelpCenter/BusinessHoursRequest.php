<?php

namespace App\Http\Requests\HelpCenter;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing a working week (docs/features/helpdesk-sla.md, §5–§6).
 *
 * One request for both, because a calendar is the same object either way.
 *
 * The week arrives as `days[mon][open]`, `days[mon][closed]` … rather than as the stored blob:
 * the form draws seven rows with a Closed switch, and asking the browser to assemble the shape
 * the column happens to hold would make the storage format part of the API.
 */
class BusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $days = array_keys((array) config('help-center.sla_week_days'));

        return [
            'name' => ['required', 'string', 'max:80'],
            // Checked against PHP's own list rather than a curated one: the SLA clock hands this
            // string to Carbon, so the only timezone that is valid here is one Carbon accepts.
            'timezone' => ['required', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'is_default' => ['boolean'],

            'days' => ['required', 'array'],
            'days.*.closed' => ['boolean'],
            // `required_if` and not `required`: a closed day sends no times, and demanding them
            // would make Saturday impossible to express.
            'days.*.open' => ['nullable', 'required_if:days.*.closed,false', 'date_format:H:i'],
            'days.*.close' => ['nullable', 'required_if:days.*.closed,false', 'date_format:H:i'],
        ] + collect($days)->mapWithKeys(fn (string $d) => ["days.$d" => ['array']])->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $open = false;

            foreach ((array) config('help-center.sla_week_days') as $key => $label) {
                $day = (array) $this->input("days.$key", []);

                if (filter_var($day['closed'] ?? false, FILTER_VALIDATE_BOOL)) {
                    continue;
                }

                $from = (string) ($day['open'] ?? '');
                $to = (string) ($day['close'] ?? '');

                // A close at or before its open is not a window — it is zero minutes of service
                // or a negative one, and the model reads both as Closed. Refusing it here means
                // the person who typed it finds out, rather than the tickets that quietly never
                // count any time.
                if ($from !== '' && $to !== '' && strcmp($from, $to) >= 0) {
                    $validator->errors()->add("days.$key.close", $label.' must close after it opens.');

                    continue;
                }

                $open = true;
            }

            /*
             * A week with every day closed is a calendar under which no SLA can ever be met.
             *
             * Every clock would sit at its start instant forever and no deadline could arrive —
             * which reads on screen as "SLA is broken", not as "we configured no working hours".
             * A Space that genuinely wants round-the-clock service leaves the policy's calendar
             * unset (SLA-D3); it does not author an empty one.
             */
            if (! $open) {
                $validator->errors()->add('days', 'Open at least one day — a week with no working hours has no SLA.');
            }
        });
    }

    /**
     * The week in the shape the column stores: `{"mon":{"open":…,"close":…}, "sat":null}`.
     *
     * Built here rather than in the controller so create and edit cannot assemble it
     * differently, which is the bug where editing a calendar silently reopens a closed day.
     *
     * @return array<string, array{open: string, close: string}|null>
     */
    public function schedule(): array
    {
        $schedule = [];

        foreach (array_keys((array) config('help-center.sla_week_days')) as $key) {
            $day = (array) $this->input("days.$key", []);

            $schedule[$key] = filter_var($day['closed'] ?? false, FILTER_VALIDATE_BOOL)
                ? null
                : ['open' => (string) $day['open'], 'close' => (string) $day['close']];
        }

        return $schedule;
    }
}
