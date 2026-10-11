<?php

namespace App\Rules;

use App\Helpers\TemplateRenderer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use MyCS\MailTemplate\TemplateError;

/**
 * The field must parse as a staff-edited template (docs/templates.md). A rule object, not a
 * closure: Filament evaluates closures given to ->rule()/->rules() with its own injection and
 * cannot resolve $attribute/$fail, which broke every save of the forms using it.
 */
class ValidTemplate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            TemplateRenderer::renderer()->validate((string) $value);
        } catch (TemplateError $e) {
            $fail($e->getMessage());
        }
    }
}
