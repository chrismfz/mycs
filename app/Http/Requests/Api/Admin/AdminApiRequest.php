<?php

namespace App\Http\Requests\Api\Admin;

use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Http\FormRequest;

abstract class AdminApiRequest extends FormRequest
{
    /**
     * An amount of money: a decimal string with two places at most, as the panel's fields take it. Never a float, an
     * exponent or a sign (a note's amount adds its own).
     */
    protected const AMOUNT = 'regex:/^\d{1,9}(\.\d{1,2})?$/';

    protected const SIGNED_AMOUNT = 'regex:/^-?\d{1,9}(\.\d{1,2})?$/';

    /** A payment's id at its gateway or bank: the column's length, no control characters. */
    protected const TRANSACTION_ID = ['string', 'max:255', 'regex:/^[^\p{C}]+$/u'];

    /** The day money arrived: a real day, not before 2000 and not after today. */
    protected const PAID_AT = ['date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'];

    /** A line's quantity: whole, and with a price of nine digits its total still fits what an amount may be. */
    protected const MAX_QUANTITY = 1000;

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

    /** A text column is limited in bytes (TEXT: 65535), and Greek takes two a letter: `max` counts letters. */
    protected static function maxBytes(int $bytes = 65535): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($bytes): void {
            if (is_string($value) && strlen($value) > $bytes) {
                $fail(__('The :attribute is too long.', ['attribute' => $attribute]));
            }
        };
    }

    /** A fee is part of the amount it was taken from: never above it. */
    protected static function feeAbove(mixed $fee, mixed $amount): bool
    {
        return is_string($fee) && RecordPaymentService::toCents($fee) > RecordPaymentService::toCents($amount);
    }

    /**
     * Default set of rules to apply to API requests.
     */
    public function rules(): array
    {
        return [];
    }
}
