<?php

namespace App\Http\Requests\Wiki;

use App\Models\WikiCollectionGuest;
use App\Services\AuthCodeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inviting somebody outside the workspace to read one collection
 * (docs/features/wiki-external-guests.md).
 */
class StoreCollectionGuestRequest extends FormRequest
{
    /** Authorization is the collection's, and the controller holds it. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            // Normalized the same way every other address in this application is, so
            // `Sarah@Client.com` and `sarah@client.com ` are one person and not two rows.
            'email' => is_string($this->email) ? AuthCodeService::normalizeEmail($this->email) : $this->email,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],

            // WG-12 — a method the application cannot perform is refused at the request rather
            // than stored and discovered by somebody who cannot get in.
            'login_method' => ['required', Rule::in(WikiCollectionGuest::loginMethods())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'login_method.in' => 'That login method is not one this application can offer yet.',
        ];
    }
}
