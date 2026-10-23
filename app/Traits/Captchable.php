<?php

namespace App\Traits;

use App\Services\Captcha\Altcha;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

trait Captchable
{
    public string $captcha = '';

    private $is_valid = false;

    public function updated($name = null)
    {
        // Solved (or a new token): the error of the try before is no longer so (owner 2026-10-23: still shown after it).
        if ($name === 'captcha') {
            $this->resetErrorBag('captcha');
        }
        // ALTCHA is checked when the form is sent, not on every field's update: a solution counts once, and a live
        // field's update before the submit would spend it (docs/captcha.md).
        // A provider's token is checked when it arrives, once: not again for every other field updated with it.
        if (config('settings.captcha') === 'altcha' || $name !== 'captcha') {
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
        if (!config('settings.captcha') || config('settings.captcha') == 'disabled') {
            return;
        }
        // A token checked when it arrived (updated(), the same request) covers one action, not every one after it:
        // a provider's token is single-use, so checking it again would refuse it (review of #384).
        if ($this->is_valid) {
            $this->is_valid = false;

            return;
        }

        if (!$this->captcha) {
            throw ValidationException::withMessages(['captcha' => __('auth.captcha_required')]);
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

        throw ValidationException::withMessages(['captcha' => __('auth.captcha_invalid')]);
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

        throw ValidationException::withMessages(['captcha' => __('auth.captcha_invalid')]);
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

        throw ValidationException::withMessages(['captcha' => __('auth.captcha_invalid')]);
    }
}
