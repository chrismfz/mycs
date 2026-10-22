<?php

namespace MyCS\Extensions\Servers\CentovaCast;

use App\Exceptions\ServerRefused;
use Exception;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Centova Cast's XML API (`api.php`, as Centova's own billing modules call it): a `system` method takes the admin's
 * password (`user|password` for an admin other than «admin»), a `server` method the account's username and the admin's
 * `user|password`. An `error` answer is Centova's refusal (ServerRefused, its message); no answer or one that cannot be
 * read is not: its outcome is not known.
 */
final class CentovaApi
{
    public function __construct(
        private readonly string $url,
        private readonly string $adminUser,
        private readonly string $adminPassword,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @param  array<string, scalar|null>  $arguments
     * @return array<string, mixed> the answer's data, as arrays (a tag met twice becomes a list)
     */
    public function system(string $method, array $arguments = []): array
    {
        $password = $this->adminUser === 'admin' ? $this->adminPassword : "{$this->adminUser}|{$this->adminPassword}";

        return $this->call('system', $method, ['password' => $password] + $arguments);
    }

    /**
     * @param  array<string, scalar|null>  $arguments
     * @return array<string, mixed>
     */
    public function server(string $username, string $method, array $arguments = []): array
    {
        return $this->call('server', $method, ['username' => $username, 'password' => "{$this->adminUser}|{$this->adminPassword}"] + $arguments);
    }

    /** The panel's address, without a trailing slash. */
    public function baseUrl(): string
    {
        return rtrim(preg_replace('#/api\.php$#', '', $this->url), '/');
    }

    /** @return array<string, mixed> */
    private function call(string $class, string $method, array $arguments): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><centovacast><request class="' . self::escape($class) . '" method="' . self::escape($method) . '">'
            . self::arguments($arguments) . '</request></centovacast>';
        try {
            $response = Http::connectTimeout(10)->timeout($this->timeout)->withoutRedirecting()
                ->withBody($xml, 'application/x-www-form-urlencoded')->post($this->baseUrl() . '/api.php');
        } catch (Exception $e) {
            // Never the request in the message: it carries the admin's password.
            throw new Exception("Centova Cast {$class}.{$method}: no answer (" . class_basename($e) . ')');
        }
        if ($response->status() !== 200) {
            throw new Exception("Centova Cast {$class}.{$method}: HTTP {$response->status()}");
        }
        $answer = self::parse($response->body());
        if ($answer === null) {
            throw new Exception("Centova Cast {$class}.{$method}: an answer that cannot be read");
        }
        [$type, $message, $data] = $answer;
        if ($type === 'error') {
            throw new ServerRefused("Centova Cast: {$message}");
        }
        if ($type !== 'success') {
            throw new Exception("Centova Cast {$class}.{$method}: an answer of type «{$type}»");
        }

        return $data;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function arguments(array $arguments): string
    {
        $xml = '';
        foreach ($arguments as $name => $value) {
            if ($value === null || !preg_match('/^[a-z][a-z0-9_]*$/i', (string) $name)) {
                continue;
            }
            $xml .= "<{$name}>" . self::escape(is_bool($value) ? (string) (int) $value : (string) $value) . "</{$name}>";
        }

        return $xml;
    }

    /** @return array{0: string, 1: string, 2: array<string, mixed>}|null type, message, data */
    private static function parse(string $body): ?array
    {
        // Centova may print a debug banner before its XML (its database error on provision, live on stream 2026-10-22):
        // the answer is the XML that follows.
        $start = strpos($body, '<centovacast');
        if ($start === false) {
            return null;
        }
        $declaration = strrpos(substr($body, 0, $start), '<?xml');
        $body = substr($body, $declaration !== false ? $declaration : $start);
        $previous = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string(self::htmlEntities($body), SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($root === false || !isset($root->response)) {
            return null;
        }
        $response = $root->response;
        $data = isset($response->data) ? self::toArray($response->data) : [];

        return [strtolower((string) $response['type']), trim((string) ($response->message ?? '')), is_array($data) ? $data : []];
    }

    /**
     * Centova writes HTML's named entities in its XML (a Greek organization as `&Iota;&Mu;…`, live on stream 2026-10-22),
     * which XML does not know: each becomes its character, XML's own five are left as they are, an unknown one is kept
     * as text, and so is a bare «&». Otherwise one such account made the whole `listaccounts` answer unreadable.
     */
    private static function htmlEntities(string $body): string
    {
        return (string) preg_replace_callback('/&(?:#[0-9]+;|#x[0-9A-Fa-f]+;|([A-Za-z][A-Za-z0-9]{0,31});)?/', function (array $match) {
            if (!isset($match[1])) {
                // A numeric reference is XML's own; a bare «&» (Rock & Roll) is text.
                return $match[0] === '&' ? '&amp;' : $match[0];
            }
            if (in_array($match[1], ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
                return $match[0];
            }
            $character = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $character !== $match[0] ? htmlspecialchars($character, ENT_XML1 | ENT_QUOTES, 'UTF-8') : '&amp;' . $match[1] . ';';
        }, $body);
    }

    /** @return array<string, mixed>|string */
    private static function toArray(SimpleXMLElement $node): array|string
    {
        if ($node->count() === 0) {
            return trim((string) $node);
        }
        $out = [];
        $seen = [];
        foreach ($node->children() as $name => $child) {
            $value = self::toArray($child);
            if (!array_key_exists($name, $out)) {
                $out[$name] = $value;
            } else {
                if (!isset($seen[$name])) {
                    $out[$name] = [$out[$name]];
                    $seen[$name] = true;
                }
                $out[$name][] = $value;
            }
        }

        return $out;
    }

    /**
     * Rows of a list answer (`<row>` once or many times), each an array.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function rows(array $data): array
    {
        $rows = $data['row'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        if (!array_is_list($rows)) {
            $rows = [$rows];
        }

        return array_values(array_filter($rows, 'is_array'));
    }
}
