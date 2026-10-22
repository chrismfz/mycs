<?php

namespace MyCS\MailTemplate;

/**
 * Renders templates that staff edit (email subject/body, in-app notifications,
 * mail header/footer, product snippets) WITHOUT compiling them to PHP. Blade was
 * a code-execution hole for anyone who could edit a template (audit-security H1).
 *
 * Syntax (a small subset of Twig, so it reads familiar):
 *
 *   {{ invoice.number }}                    value, HTML-escaped when $escape is true
 *   {{ service.expires_at|date("F j, Y") }} filters: date("fmt"), default("x"), upper, lower, raw, url (a link's
 *   address: http, https, mailto, tel, sms, viber, whatsapp, skype, callto, ftp, geo or relative, anything else is "#")
 *   {% if invoice.total %} … {% else %} … {% endif %}
 *   {% if not item.reason %} … {% endif %}
 *   {% if cancellation.type == "end_of_period" %} … {% endif %}   (also !=)
 *   {% for item in items %} {{ loop.index }} {{ item.description }} {% endfor %}
 *
 * A path only READS data: array keys, ArrayAccess offsets and public properties; no function
 * is called. An ArrayAccess object decides what an offset does (an Eloquent model calls a
 * relation method for it): the app's accessor must allow only reads (MyCS: TemplateRenderer).
 * Unknown paths render as an empty string; a syntax error throws TemplateError.
 *
 * A tag alone on its line takes the whole line with it, so loops can build Markdown
 * tables row by row without leaving blank lines behind.
 */
final class Renderer
{
    private const FILTERS = ['raw', 'date', 'default', 'upper', 'lower', 'url'];

    /** @var \Closure(mixed, string): mixed */
    private \Closure $accessor;

    /** @var (\Closure(object): bool)|null */
    private ?\Closure $printable;

    /**
     * @param  (\Closure(mixed $target, string $key): mixed)|null  $accessor  reads one path segment;
     *                                                                        the app wraps {@see self::read()} to hide fields (e.g. a model's $hidden).
     * @param  (\Closure(object $value): bool)|null  $printable  whether a Stringable object may be printed as a whole;
     *                                                           the app refuses records, whose string is all their fields.
     */
    /** @var (\Closure(string, bool): string)|null */
    private ?\Closure $emit = null;

    public function __construct(?\Closure $accessor = null, ?\Closure $printable = null)
    {
        $this->accessor = $accessor ?? self::read(...);
        $this->printable = $printable;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $escape  HTML-escape values (true for HTML/Markdown output, false for plain text)
     */
    public function render(string $template, array $data, bool $escape = true): string
    {
        return $this->renderNodes($this->parse($template), [$data], $escape);
    }

    /**
     * Renders, but each value comes out as what `$emit` returns for it (its text, escaped or raw as it would be, and
     * whether it is raw): for a caller that puts the values in after a step of its own (Markdown, in the app).
     *
     * @param  array<string, mixed>  $data
     * @param  \Closure(string $text, bool $raw): string  $emit
     */
    public function renderWith(string $template, array $data, \Closure $emit, bool $escape = true): string
    {
        $previous = $this->emit;
        $this->emit = $emit;
        try {
            return $this->renderNodes($this->parse($template), [$data], $escape);
        } finally {
            $this->emit = $previous;
        }
    }

    /** Throws TemplateError when the template does not parse. */
    public function validate(string $template): void
    {
        $this->parse($template);
    }

    /** Default segment reader: array key, ArrayAccess offset or public property; null when absent. */
    public static function read(mixed $target, string $key): mixed
    {
        if (is_array($target)) {
            return $target[$key] ?? null;
        }
        if ($target instanceof \ArrayAccess) {
            return $target->offsetExists($key) ? $target->offsetGet($key) : null;
        }
        if (is_object($target) && array_key_exists($key, get_object_vars($target))) {
            return $target->{$key};
        }

        return null;
    }

    // ---- parsing ---------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function parse(string $template): array
    {
        $parts = preg_split('/(\{\{.*?\}\}|\{%.*?%\})/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $tokens = [];
        $line = 1;
        foreach ($parts as $i => $part) {
            $isTag = $i % 2 === 1;
            $tokens[] = ['tag' => $isTag, 'src' => $part, 'line' => $line];
            $line += substr_count($part, "\n");
        }
        $tokens = $this->trimStandaloneTags($tokens);

        // Frames of open blocks, innermost last; each collects its own children.
        $frames = [['kind' => 'root', 'nodes' => []]];

        foreach ($tokens as $token) {
            if (!$token['tag']) {
                if ($token['src'] !== '') {
                    $this->append($frames, ['type' => 'text', 'text' => $token['src']]);
                }

                continue;
            }
            $src = $token['src'];
            $at = $token['line'];
            $inner = trim(substr($src, 2, -2));

            if (str_starts_with($src, '{{')) {
                $this->append($frames, ['type' => 'out'] + $this->parseOutput($inner, $at));

                continue;
            }

            [$keyword, $rest] = array_pad(preg_split('/\s+/', $inner, 2), 2, '');
            $top = array_key_last($frames);

            switch ($keyword) {
                case 'if':
                    $frames[] = ['kind' => 'if', 'line' => $at, 'cond' => $this->parseCondition($rest, $at), 'then' => [], 'else' => [], 'branch' => 'then'];
                    break;

                case 'else':
                    $this->expectEmpty($rest, 'else', $at);
                    if ($frames[$top]['kind'] !== 'if' || $frames[$top]['branch'] !== 'then') {
                        throw new TemplateError("Line {$at}: {% else %} without an open {% if %}");
                    }
                    $frames[$top]['branch'] = 'else';
                    break;

                case 'for':
                    if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+in\s+(\S+)$/', trim($rest), $m) || $m[1] === 'loop') {
                        throw new TemplateError("Line {$at}: expected {% for item in list %}");
                    }
                    $frames[] = ['kind' => 'for', 'line' => $at, 'var' => $m[1], 'path' => $this->parsePath($m[2], $at), 'body' => []];
                    break;

                case 'endif':
                case 'endfor':
                    $this->expectEmpty($rest, $keyword, $at);
                    $want = substr($keyword, 3);
                    if ($frames[$top]['kind'] !== $want) {
                        throw new TemplateError("Line {$at}: {% {$keyword} %} without an open {% {$want} %}");
                    }
                    $frame = array_pop($frames);
                    $this->append($frames, $want === 'if'
                        ? ['type' => 'if', 'cond' => $frame['cond'], 'then' => $frame['then'], 'else' => $frame['else']]
                        : ['type' => 'for', 'var' => $frame['var'], 'path' => $frame['path'], 'body' => $frame['body']]);
                    break;

                default:
                    throw new TemplateError("Line {$at}: unknown tag {% {$keyword} %}");
            }
        }

        $open = $frames[array_key_last($frames)];
        if ($open['kind'] !== 'root') {
            throw new TemplateError("Line {$open['line']}: {% {$open['kind']} %} is never closed");
        }

        return $open['nodes'];
    }

    /** @param  array<array-key, array<mixed>>  $frames */
    private function append(array &$frames, array $node): void
    {
        $top = array_key_last($frames);
        $list = match ($frames[$top]['kind']) {
            'root' => 'nodes',
            'for' => 'body',
            'if' => $frames[$top]['branch'],
            default => throw new \LogicException('Unknown frame kind'),
        };
        $frames[$top][$list][] = $node;
    }

    /**
     * A {% tag %} that is the only thing on its line removes that line (indent + newline).
     *
     * @param  array<int, array{tag: bool, src: string, line: int}>  $tokens
     * @return array<int, array{tag: bool, src: string, line: int}>
     */
    private function trimStandaloneTags(array $tokens): array
    {
        $src = array_column($tokens, 'src');
        $count = count($src);
        for ($i = 1; $i < $count; $i += 2) {
            if (!str_starts_with($src[$i], '{%')) {
                continue;
            }
            $before = $src[$i - 1];
            $after = $src[$i + 1] ?? '';
            // An empty text token between two tags means another tag shares the line.
            $startsLine = ($before !== '' || $i === 1) && preg_match('/(^|\n)[ \t]*$/', $before) === 1;
            $endsLine = ($after !== '' || $i + 1 >= $count - 1) && preg_match('/^[ \t]*(\r?\n|$)/', $after) === 1;
            if ($startsLine && $endsLine) {
                $src[$i - 1] = (string) preg_replace('/[ \t]*$/', '', $before);
                if (isset($src[$i + 1])) {
                    $src[$i + 1] = (string) preg_replace('/^[ \t]*(\r?\n)?/', '', $after, 1);
                }
            }
        }
        foreach ($tokens as $k => $token) {
            $tokens[$k]['src'] = $src[$k];
        }

        return $tokens;
    }

    private function expectEmpty(string $rest, string $tag, int $at): void
    {
        if (trim($rest) !== '') {
            throw new TemplateError("Line {$at}: {% {$tag} %} takes no arguments");
        }
    }

    /** @return array{path: list<string>, filters: list<array{name: string, arg: ?string}>, line: int} */
    private function parseOutput(string $expr, int $at): array
    {
        $tokens = $this->lex($expr, $at);
        if ($tokens === []) {
            throw new TemplateError("Line {$at}: empty {{ }}");
        }
        $path = $this->parsePath(array_shift($tokens), $at);
        $filters = [];
        while ($tokens !== []) {
            if (array_shift($tokens) !== '|') {
                throw new TemplateError("Line {$at}: expected | before a filter in {{ {$expr} }}");
            }
            $name = array_shift($tokens);
            if (!in_array($name, self::FILTERS, true)) {
                throw new TemplateError("Line {$at}: unknown filter '{$name}'");
            }
            $arg = null;
            if (($tokens[0] ?? null) === '(') {
                array_shift($tokens);
                $literal = array_shift($tokens);
                if ($literal === null || !$this->isString($literal) || array_shift($tokens) !== ')') {
                    throw new TemplateError("Line {$at}: filter '{$name}' takes one quoted argument");
                }
                $arg = substr($literal, 1, -1);
            }
            if (in_array($name, ['date', 'default'], true) && $arg === null) {
                throw new TemplateError("Line {$at}: filter '{$name}' needs an argument, e.g. {$name}(\"…\")");
            }
            $filters[] = ['name' => $name, 'arg' => $arg];
        }

        return ['path' => $path, 'filters' => $filters, 'line' => $at];
    }

    /** @return array{not: bool, left: array{0: string, 1: mixed}, op: ?string, right: ?array{0: string, 1: mixed}} */
    private function parseCondition(string $expr, int $at): array
    {
        $tokens = $this->lex($expr, $at);
        $not = false;
        if (($tokens[0] ?? null) === 'not') {
            $not = true;
            array_shift($tokens);
        }
        $left = $this->parseOperand(array_shift($tokens), $at);
        $op = null;
        $right = null;
        if ($tokens !== []) {
            $op = array_shift($tokens);
            if (!in_array($op, ['==', '!='], true)) {
                throw new TemplateError("Line {$at}: only == and != can compare values");
            }
            $right = $this->parseOperand(array_shift($tokens), $at);
        }
        if ($tokens !== []) {
            throw new TemplateError("Line {$at}: unexpected '{$tokens[0]}' in {% if {$expr} %}");
        }

        return ['not' => $not, 'left' => $left, 'op' => $op, 'right' => $right];
    }

    /** @return array{0: string, 1: mixed} */
    private function parseOperand(?string $token, int $at): array
    {
        if ($token === null) {
            throw new TemplateError("Line {$at}: missing value in {% if %}");
        }
        if ($this->isString($token)) {
            return ['lit', substr($token, 1, -1)];
        }
        if (is_numeric($token)) {
            return ['lit', $token];
        }

        return ['path', $this->parsePath($token, $at)];
    }

    /** @return list<string> */
    private function parsePath(?string $token, int $at): array
    {
        if ($token === null || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/', $token)) {
            throw new TemplateError("Line {$at}: '{$token}' is not a value name (use e.g. invoice.number)");
        }

        return explode('.', $token);
    }

    /** @return list<string> */
    private function lex(string $expr, int $at): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($expr);
        while ($offset < $length) {
            if (!preg_match('/\G\s*("[^"]*"|\'[^\']*\'|==|!=|[|()]|[A-Za-z0-9_.]+)\s*/', $expr, $m, 0, $offset)) {
                throw new TemplateError("Line {$at}: cannot read '" . trim(substr($expr, $offset)) . "'");
            }
            $tokens[] = $m[1];
            $offset += strlen($m[0]);
        }

        return $tokens;
    }

    private function isString(string $token): bool
    {
        return strlen($token) >= 2 && ($token[0] === '"' || $token[0] === "'") && $token[-1] === $token[0];
    }

    // ---- rendering -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $scopes  innermost last
     */
    private function renderNodes(array $nodes, array $scopes, bool $escape): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['type']) {
                'text' => $node['text'],
                'out' => $this->renderOutput($node, $scopes, $escape),
                'if' => $this->renderNodes($this->test($node['cond'], $scopes) ? $node['then'] : $node['else'], $scopes, $escape),
                'for' => $this->renderLoop($node, $scopes, $escape),
                default => throw new \LogicException('Unknown node type'),
            };
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $scopes */
    private function renderOutput(array $node, array $scopes, bool $escape): string
    {
        $value = $this->resolve($node['path'], $scopes);
        $raw = $value instanceof Html;
        foreach ($node['filters'] as $filter) {
            switch ($filter['name']) {
                case 'raw':
                    $raw = true;
                    break;
                case 'date':
                    if ($value instanceof \DateTimeInterface) {
                        $value = $value->format($filter['arg']);
                        $raw = false;
                    }
                    break;
                case 'default':
                    if ($this->stringify($value) === '') {
                        $value = $filter['arg'];
                        $raw = false;
                    }
                    break;
                case 'upper':
                case 'lower':
                    $value = $filter['name'] === 'upper' ? mb_strtoupper($this->stringify($value)) : mb_strtolower($this->stringify($value));
                    $raw = false;
                    break;
                case 'url':
                    $value = self::safeUrl($this->stringify($value));
                    $raw = false;
                    break;
            }
        }
        $text = $this->stringify($value);
        $text = $escape && !$raw ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;

        return $this->emit === null ? $text : ($this->emit)($text, $raw);
    }

    /** A link's address: http(s), mailto, tel, sms, viber, whatsapp, skype, callto, ftp, geo or one without a scheme; anything else (javascript:, data:) is "#". */
    public static function safeUrl(string $url): string
    {
        // Browsers ignore control characters and spaces in a scheme (java\tscript:): so does this check.
        $plain = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $url));
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $plain, $m) === 1 && !in_array($m[1], ['http', 'https', 'mailto', 'tel', 'sms', 'viber', 'whatsapp', 'skype', 'callto', 'ftp', 'geo'], true)) {
            return '#';
        }

        return $url;
    }

    /** @param  list<array<string, mixed>>  $scopes */
    private function renderLoop(array $node, array $scopes, bool $escape): string
    {
        $list = $this->resolve($node['path'], $scopes);
        if ($list instanceof \Traversable) {
            $list = iterator_to_array($list, false);
        }
        if (!is_array($list)) {
            return '';
        }
        $list = array_values($list);
        $count = count($list);
        $out = '';
        foreach ($list as $i => $item) {
            $scope = [
                $node['var'] => $item,
                'loop' => ['index' => $i + 1, 'first' => $i === 0, 'last' => $i === $count - 1],
            ];
            $out .= $this->renderNodes($node['body'], [...$scopes, $scope], $escape);
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $scopes */
    private function test(array $cond, array $scopes): bool
    {
        $left = $this->operand($cond['left'], $scopes);
        if ($cond['op'] === null) {
            $result = $this->truthy($left);
        } else {
            $same = $this->stringify($left) === $this->stringify($this->operand($cond['right'], $scopes));
            $result = $cond['op'] === '==' ? $same : !$same;
        }

        return $cond['not'] ? !$result : $result;
    }

    /** @param  list<array<string, mixed>>  $scopes */
    private function operand(array $operand, array $scopes): mixed
    {
        return $operand[0] === 'lit' ? $operand[1] : $this->resolve($operand[1], $scopes);
    }

    /**
     * @param  list<string>  $path
     * @param  list<array<string, mixed>>  $scopes
     */
    private function resolve(array $path, array $scopes): mixed
    {
        $first = array_shift($path);
        $value = null;
        for ($i = count($scopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($first, $scopes[$i])) {
                $value = $scopes[$i][$first];
                break;
            }
        }
        foreach ($path as $segment) {
            if ($value === null) {
                return null;
            }
            $value = ($this->accessor)($value, $segment);
        }

        return $value;
    }

    private function truthy(mixed $value): bool
    {
        if ($value instanceof \Countable) {
            return count($value) > 0;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_object($value)) {
            // A value that may not print is still there: true, without turning it into text (a record into JSON).
            if (!$value instanceof \Stringable || ($this->printable !== null && !($this->printable)($value))) {
                return true;
            }

            return (string) $value !== '';
        }

        return (bool) $value;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => '1',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof \Stringable => $this->printable === null || ($this->printable)($value) ? (string) $value : '',
            default => '',
        };
    }
}
