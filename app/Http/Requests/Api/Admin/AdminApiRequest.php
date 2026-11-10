<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

abstract class AdminApiRequest extends FormRequest
{
    /**
     * An amount of money: a decimal string with two places at most, as the panel's fields take it. Never a float, an
     * exponent or a sign (a note's amount adds its own).
     */
    protected const AMOUNT = 'regex:/^\d{1,9}(\.\d{1,2})?$/';

    protected const SIGNED_AMOUNT = 'regex:/^-?\d{1,9}(\.\d{1,2})?$/';

    protected $permission;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->hasApiPermission('admin.' . $this->permission);
    }

    protected function hasApiPermission(string $permission): bool
    {
        return in_array($permission, $this->instance()->attributes->get('api_key_permissions', []));
    }

    /**
     * Default set of rules to apply to API requests.
     */
    public function rules(): array
    {
        return [];
    }
}
