<?php

namespace MyCS\GrEpp;

use Closure;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Http\Transport;
use Throwable;

/**
 * One session at the registry (grepp.md §2; the guide v4.3 «Επικοινωνία με τον Εξυπηρετητή EPP»): EPP XML POSTed over
 * HTTPS, the session a JSESSIONID cookie, commands one at a time (the caller holds a lock per account: never two at
 * once on one session).
 *
 * - Logged in when first needed (a hello for the cookie, then login); again, in a new session, after the idle limit
 *   (Config::maxIdle, 5 minutes; the registry ends a session idle for 15), after 25xx (the registry closed it), and once
 *   if a command finds the session gone (2002, the guide's «invalid session»: nothing done, so sent again once).
 * - command() returns a 1xxx response (1001, a create's «pending», is success) and throws:
 *   Refused (the registry said no: nothing done), OutcomeUnknown (sent, no usable answer: maybe done; never resent),
 *   NotSent (never reached the registry: no session, no connection). These three meanings are command()'s.
 */
final class Session
{
    /** Results about the session or the server, not a refusal of the command itself: the outcome is not known. */
    private const UNKNOWN_CODES = [2400, 2500, 2501, 2502];

    /** The registry closes the session with these (RFC 5730). */
    private const CLOSING_CODES = [2500, 2501, 2502];

    /** @var array<string, string> the cookies the registry set (name => value): JSESSIONID, and any a balancer adds */
    private array $cookies = [];

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

    /** A new client transaction id, for a command's <clTRID> (its answer must carry it back: command()). */
    public function newTransactionId(): string
    {
        return ($this->transactionId)();
    }

    /** The registry's greeting; also keeps the session alive (and gives the first cookie). Changes nothing. */
    public function hello(): Response
    {
        $response = $this->send(Frame::hello(), sent: false);
        if (!$response->greeting) {
            throw new NotSent('The registry did not answer hello with a greeting.');
        }

        return $response;
    }

    /**
     * Log in (a new session).
     *
     * With $newPassword the registry changes the account's password in this login: an answer lost after sending is
     * OutcomeUnknown (it may have changed: try the new one before the old, review 2 of #184).
     *
     * @throws Refused the registry refused the login (2xxx but 24xx/25xx: the user or password, the account)
     * @throws NotSent no connection, no usable answer, or the registry busy or failing (24xx/25xx: try later)
     * @throws OutcomeUnknown only with $newPassword
     */
    public function login(#[\SensitiveParameter] ?string $newPassword = null): Response
    {
        $this->loggedIn = false;
        $changing = $newPassword !== null;
        if ($changing) {
            Config::checkPassword($newPassword); // before anything is sent (adversarial ADV-7 of #184)
        }
        try {
            if (!isset($this->cookies['JSESSIONID'])) {
                $this->hello();
            }
            $response = $this->send(Frame::login($this->config, $this->newTransactionId(), $newPassword), sent: $changing);
        } catch (OutcomeUnknown $e) {
            if ($changing) {
                throw $e;
            }
            throw new NotSent('No session at the registry: ' . $e->getMessage(), $e);
        }
        if ($response->greeting || $response->code === null) {
            throw $changing ? new OutcomeUnknown('The registry did not answer the login.') : new NotSent('The registry did not answer the login.');
        }
        if ($response->code === 1000) {
            $this->loggedIn = true;

            return $response;
        }
        if ($response->code >= 2400 || $response->code < 2000) {
            // The registry busy or failing, not a refusal of this account (review 3 of #184); a password change then may
            // have been done (adversarial ADV-1b).
            $message = "The registry could not log in now ({$response->code}): {$response->message}";
            throw $changing ? new OutcomeUnknown($message, $response->code) : new NotSent($message);
        }

        throw new Refused($response->code, (string) $response->message, $response->svTRID);
    }

    /**
     * Send one command (an <epp> document); log in first when needed. With $clTRID (the one in $xml) the answer must
     * carry it back, else it is not this command's answer: OutcomeUnknown (review 11 of #184).
     *
     * @throws Refused|OutcomeUnknown|NotSent
     */
    public function command(#[\SensitiveParameter] string $xml, ?string $clTRID = null): Response
    {
        $this->ensureLoggedIn();
        $response = $this->send($xml, sent: true);
        if ($response->code === 2002) {
            // The session is gone (the guide: 2509 «invalid session» became 2002): nothing was done. Once more, in a new
            // session (logged out, a new cookie: a login on a live session is refused itself, review 1 of #184); a 2002
            // then is the command's own («Λάθος χρήση εντολής», an empty update).
            $this->logout();
            $this->ensureLoggedIn();
            $response = $this->send($xml, sent: true);
        }

        return $this->outcome($response, $clTRID);
    }

    /** End the session (1500); never throws: a session not ended expires on its own. */
    public function logout(): void
    {
        if ($this->loggedIn) {
            try {
                $this->send(Frame::logout($this->newTransactionId()), sent: false);
            } catch (Throwable) {
                // expired on its own after 15 minutes
            }
        }
        $this->loggedIn = false;
        $this->cookies = [];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['config' => $this->config, 'loggedIn' => $this->loggedIn, 'cookies' => array_keys($this->cookies)];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('An EPP session is not serialised: it holds the password.');
    }

    private function ensureLoggedIn(): void
    {
        $idle = $this->lastUsed !== null && ($this->clock)() - $this->lastUsed > $this->config->maxIdle;
        if ($this->loggedIn && !$idle) {
            return;
        }
        if ($idle || $this->cookies !== []) {
            $this->logout(); // a new session rather than one the registry may have ended
        }
        try {
            $this->login();
        } catch (Refused $e) {
            throw new NotSent("The registry refused the login ({$e->resultCode}): {$e->registryMessage}", $e);
        }
    }

    private function outcome(Response $response, ?string $clTRID): Response
    {
        if ($response->greeting || $response->code === null) {
            throw new OutcomeUnknown('The registry answered a command with a greeting.');
        }
        if (in_array($response->code, self::CLOSING_CODES, true)) {
            $this->loggedIn = false; // the registry closed the session (review 7 of #184)
            $this->cookies = [];
        }
        if ($clTRID !== null && $response->clTRID !== $clTRID) {
            throw new OutcomeUnknown("The registry's answer is not this command's (clTRID {$response->clTRID}, sent {$clTRID}).", $response->code);
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
     * POST one frame; keep the cookies it sets.
     *
     * @param  bool  $sent  a command that may change something: a failure after sending is OutcomeUnknown; before, NotSent
     */
    private function send(#[\SensitiveParameter] string $xml, bool $sent): Response
    {
        $headers = ['Content-Type' => $this->config->contentType];
        if ($this->cookies !== []) {
            $headers['Cookie'] = implode('; ', array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($this->cookies), $this->cookies));
        }
        $http = $this->transport->post($this->config->endpoint, $xml, $headers);
        if ($http->status === 0) {
            $message = 'No answer from the registry: ' . ($http->error ?? 'transport error');
            throw $http->neverSent || !$sent ? new NotSent($message) : new OutcomeUnknown($message);
        }
        if ($http->status !== 200) {
            throw $sent ? new OutcomeUnknown("The registry answered HTTP {$http->status}.") : new NotSent("The registry answered HTTP {$http->status}.");
        }
        $this->keepCookies($http);
        $this->lastUsed = ($this->clock)();

        try {
            return Response::parse($http->body);
        } catch (OutcomeUnknown $e) {
            throw $sent ? $e : new NotSent($e->getMessage(), $e);
        }
    }

    /**
     * The cookies an answer sets, each by its name (the part before the first «;», EppClient.java): a balancer's cookie
     * never stands in for JSESSIONID (review 4 of #184); an empty value or Max-Age=0 deletes one. Only from a 200.
     */
    private function keepCookies(HttpResponse $http): void
    {
        foreach ($http->setCookies as $header) {
            $parts = explode(';', $header);
            $pair = trim((string) array_shift($parts));
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $pair, 2));
            if ($name === '' || preg_match('/[\x00-\x20;,]/', $name . $value) === 1) {
                continue; // not a cookie we send back (spaces, separators, control characters)
            }
            $expired = $value === '' || preg_grep('/^\s*max-age\s*=\s*(0|-\d+)\s*$/i', $parts) !== [];
            if ($expired) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
    }
}
