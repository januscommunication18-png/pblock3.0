<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Workspace logo upload (spec §4 / SET-G-006). Type + size validated. */
class UploadWorkspaceLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => [
                'required', 'file',
                'mimes:'.implode(',', config('settings.logo.mimes')),
                'max:'.(int) config('settings.logo.max_kb'),
            ],
        ];
    }
}
