<?php

namespace MyCS\GrEpp;

use Closure;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Http\Transport;
use Throwable;

/**
 * One session at the registry (grepp.md §2; the guide v4.3 «Επικοινωνία με τον Εξυπηρετητή EPP»): EPP XML POSTed over
 * HTTPS, the session a JSESSIONID cookie (the part before the first «;»), commands one at a time (the caller holds a
 * lock per account: never two at once on one cookie).
 *
 * - Logged in when first needed (a hello for the cookie, then login); again after the registry's idle limit (15
 *   minutes; Config::maxIdle, 5 by default) and once if a command finds the session gone (2002: the guide's «invalid
 *   session», nothing done, so sent again once).
 * - command() returns a 1xxx response (1001, a create's «pending», is success) and throws:
 *   Refused (the registry said no: nothing done), OutcomeUnknown (sent, no usable answer: maybe done; never resent),
 *   NotSent (never reached the registry: no session, no connection).
 */
final class Session
{
    /** Results about the session or the server, not a refusal of the command itself. */
    private const UNKNOWN_CODES = [2400, 2500, 2501, 2502];

    private ?string $cookie = null;

    private bool $loggedIn = false;

    private ?float $lastUsed = null;

    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(): string */
    private Closure $transactionId;

    /**
     * @param  (Closure(): float)|null  $clock  seconds (tests move it)
     * @param  (Closure(): string)|null  $transactionId  a new clTRID
     */
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        ?Closure $clock = null,
        ?Closure $transactionId = null,
    ) {
        $this->clock = $clock ?? fn (): float => microtime(true);
        $this->transactionId = $transactionId ?? fn (): string => 'mycs-' . bin2hex(random_bytes(8));
    }

    public function loggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** The registry's greeting; also keeps the session alive (and gives the first cookie). */
    public function hello(): Response
    {
        $response = $this->send(Frame::hello(), sent: false);
        if (!$response->greeting) {
            throw new OutcomeUnknown('The registry did not answer hello with a greeting.');
        }

        return $response;
    }

    /**
     * Log in (a new session). With $newPassword the registry changes the account's password in the same login.
     *
     * @throws Refused the registry refused the login (a wrong user or password: nothing else will work)
     * @throws NotSent no connection, or no usable answer
     */
    public function login(#[\SensitiveParameter] ?string $newPassword = null): Response
    {
        $this->loggedIn = false;
        try {
            if ($this->cookie === null) {
                $this->hello();
            }
            $response = $this->send(Frame::login($this->config, ($this->transactionId)(), $newPassword), sent: false);
        } catch (OutcomeUnknown $e) {
            throw new NotSent('No session at the registry: ' . $e->getMessage(), $e);
        }
        if ($response->greeting || $response->code !== 1000) {
            throw $response->greeting ? new NotSent('The registry did not answer the login.')
                : new Refused((int) $response->code, (string) $response->message, $response->svTRID);
        }
        $this->loggedIn = true;

        return $response;
    }

    /**
     * Send one command (an <epp> document); log in first when needed.
     *
     * @throws Refused|OutcomeUnknown|NotSent
     */
    public function command(string $xml): Response
    {
        $this->ensureLoggedIn();
        $response = $this->send($xml, sent: true);
        if ($response->code === 2002) {
            // The session is gone (the guide: 2509 «invalid session» became 2002): nothing was done. Once more, logged
            // in again; a 2002 then is the command's own («Λάθος χρήση εντολής»).
            $this->loggedIn = false;
            $this->ensureLoggedIn();
            $response = $this->send($xml, sent: true);
        }

        return $this->outcome($response);
    }

    /** End the session (1500); never throws: a session not ended expires on its own. */
    public function logout(): void
    {
        if ($this->loggedIn) {
            try {
                $this->send(Frame::logout(($this->transactionId)()), sent: false);
            } catch (Throwable) {
                // expired on its own after 15 minutes
            }
        }
        $this->loggedIn = false;
        $this->cookie = null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['config' => $this->config, 'loggedIn' => $this->loggedIn, 'cookie' => $this->cookie !== null ? '(set)' : null];
    }

    private function ensureLoggedIn(): void
    {
        $idle = $this->lastUsed !== null && ($this->clock)() - $this->lastUsed > $this->config->maxIdle;
        if ($this->loggedIn && !$idle) {
            return;
        }
        if ($idle) {
            $this->logout(); // a fresh session rather than one the registry may have ended
        }
        try {
            $this->login();
        } catch (Refused $e) {
            throw new NotSent("The registry refused the login ({$e->resultCode}): {$e->registryMessage}", $e);
        }
    }

    private function outcome(Response $response): Response
    {
        if ($response->greeting || $response->code === null) {
            throw new OutcomeUnknown('The registry answered a command with a greeting.');
        }
        if ($response->isSuccess()) {
            return $response;
        }
        if (in_array($response->code, self::UNKNOWN_CODES, true) || $response->code < 2000 || $response->code >= 3000) {
            throw new OutcomeUnknown("The registry answered {$response->code}: {$response->message}", $response->code);
        }

        throw new Refused($response->code, (string) $response->message, $response->svTRID);
    }

    /**
     * POST one frame; keep the cookie it sets.
     *
     * @param  bool  $sent  a command that may change something: a failure after sending is OutcomeUnknown; before, NotSent
     */
    private function send(string $xml, bool $sent): Response
    {
        $headers = ['Content-Type' => 'text/xml;charset=UTF-8'];
        if ($this->cookie !== null) {
            $headers['Cookie'] = $this->cookie;
        }
        $http = $this->transport->post($this->config->endpoint, $xml, $headers);
        $this->keepCookie($http);
        if ($http->status === 0) {
            $message = 'No answer from the registry: ' . ($http->error ?? 'transport error');
            throw $http->neverSent || !$sent ? new NotSent($message) : new OutcomeUnknown($message);
        }
        if ($http->status !== 200) {
            throw $sent ? new OutcomeUnknown("The registry answered HTTP {$http->status}.") : new NotSent("The registry answered HTTP {$http->status}.");
        }
        $this->lastUsed = ($this->clock)();

        return Response::parse($http->body);
    }

    /** The session's cookie: JSESSIONID if named, else the first; the part before the first «;» (EppClient.java). */
    private function keepCookie(HttpResponse $http): void
    {
        $first = null;
        foreach ($http->setCookies as $header) {
            $pair = trim(explode(';', $header, 2)[0]);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            if (stripos($pair, 'JSESSIONID=') === 0) {
                $this->cookie = $pair;

                return;
            }
            $first ??= $pair;
        }
        if ($first !== null) {
            $this->cookie = $first;
        }
    }
}
