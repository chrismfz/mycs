<?php

namespace App\Traits;

use App\Services\Captcha\Altcha;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

trait Captchable
{
    public string $captcha = '';

    private $is_valid = false;

    public function updated()
    {
        // ALTCHA is checked when the form is sent, not on every field's update: a solution counts once, and a live
        // field's update before the submit would spend it (docs/captcha.md).
        if (config('settings.captcha') === 'altcha') {
            return;
        }
        $this->captcha();
    }

    /**
     * The captcha: the one chosen in Settings, and our ALTCHA when none is (it needs no keys): a public form that costs us
     * (a mail, a registry's lookup) never goes without one (review of #251; whois, 2026-10-23).
     */
    protected function requireCaptchaOrAltcha(): void
    {
        if (in_array(config('settings.captcha'), [null, '', 'disabled'], true)) {
            if ($this->captcha === '') {
                throw ValidationException::withMessages(['captcha' => __('auth.captcha_invalid')]);
            }
            $this->altcha($this->captcha);

            return;
        }
        $this->captcha();
    }

    /** The captcha the page shows: the chosen one, or ours. */
    public static function captchaKind(): string
    {
        $kind = (string) config('settings.captcha');

        return in_array($kind, ['', 'disabled'], true) ? 'altcha' : $kind;
    }

    // Captchable
    private function captcha()
    {
        if (!config('settings.captcha') || config('settings.captcha') == 'disabled' || $this->is_valid) {
            return;
        }

        if (!$this->captcha) {
            throw ValidationException::withMessages(['captcha' => 'The CAPTCHA is required.']);
        }

        if (config('settings.captcha') == 'turnstile') {
            $this->turnstile($this->captcha);
        } elseif (config('settings.captcha') == 'hcaptcha') {
            $this->hcaptcha($this->captcha);
        } elseif (config('settings.captcha') == 'recaptcha-v2' || config('settings.captcha') == 'recaptcha-v3') {
            $this->recaptcha($this->captcha);
        } elseif (config('settings.captcha') == 'altcha') {
            $this->altcha($this->captcha);
        }
    }

    // ALTCHA, our own (docs/captcha.md): checked here, nothing sent anywhere; a solution counts once.
    private function altcha(string $value): bool
    {
        if (app(Altcha::class)->verify($value)) {
            return $this->is_valid = true;
        }

        throw ValidationException::withMessages(['captcha' => __('auth.captcha_invalid')]);
    }

    // Turnstile
    private function turnstile($value)
    {
        $itempotencyKey = uniqid();

        $response = Http::asForm()->acceptJson()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('settings.captcha_secret'),
            'response' => $value,
            'remoteip' => request()->ip(),
            'idempotency_key' => $itempotencyKey,
        ]);

        if ($response->json()['success']) {
            return $this->is_valid = true;
        }

        $subResponse = Http::asForm()->acceptJson()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('settings.captcha_secret'),
            'response' => $value,
            'remoteip' => request()->ip(),
            'idempotency_key' => $itempotencyKey,
        ]);

        if ($subResponse->json()['success']) {
            return $this->is_valid = true;
        }

        throw ValidationException::withMessages(['captcha' => 'The CAPTCHA was invalid.']);
    }

    // Google Recaptcha
    private function recaptcha($value)
    {
        $response = Http::asForm()->acceptJson()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => config('settings.captcha_secret'),
            'response' => $value,
            'remoteip' => request()->ip(),
        ]);

        if ($response->json()['success']) {
            return $this->is_valid = true;
        }

        throw ValidationException::withMessages(['captcha' => 'The CAPTCHA was invalid.']);
    }

    // hCaptcha
    private function hcaptcha($value)
    {
        $response = Http::asForm()->acceptJson()->post('https://api.hcaptcha.com/siteverify', [
            'secret' => config('settings.captcha_secret'),
            'response' => $value,
            'remoteip' => request()->ip(),
        ]);

        if ($response->json()['success']) {
            return $this->is_valid = true;
        }

        throw ValidationException::withMessages(['captcha' => 'The CAPTCHA was invalid.']);
    }
}
