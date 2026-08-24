<?php

namespace App\Http\Requests\HelpCenter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One holiday (docs/features/helpdesk-sla.md, §7).
 */
class SlaHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            /*
             * A DATE and not a datetime.
             *
             * A holiday is a day in the calendar's timezone (§7), and accepting an instant would
             * invite one — a stored 00:00 in the server's zone is the day before somewhere, and
             * Christmas that starts on the 24th is the kind of bug nobody finds until December.
             */
            'date' => ['required', 'date_format:Y-m-d'],
            'repeats_annually' => ['boolean'],
        ];
    }
}
