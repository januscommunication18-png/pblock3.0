<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowed = array_keys(config('onboarding.goals'));

        return [
            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => ['string', Rule::in($allowed)],
        ];
    }
}
