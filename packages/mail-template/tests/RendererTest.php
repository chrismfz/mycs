<?php

namespace MyCS\MailTemplate\Tests;

use MyCS\MailTemplate\Html;
use MyCS\MailTemplate\Renderer;
use MyCS\MailTemplate\TemplateError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function render(string $template, array $data = [], bool $escape = true): string
    {
        return (new Renderer)->render($template, $data, $escape);
    }

    public function test_a_stringable_the_app_refuses_prints_nothing(): void
    {
        $record = new class implements \Stringable
        {
            public string $name = 'Visible';

            public function __toString(): string
            {
                return '{"name":"Visible","notes":"Internal"}';
            }
        };
        $refuse = new Renderer(null, fn (object $value) => !$value instanceof \Stringable || $value instanceof Html);

        $this->assertSame('[]', $refuse->render('[{{ record }}]', ['record' => $record]));
        $this->assertSame('Visible', $refuse->render('{{ record.name }}', ['record' => $record]), 'its fields still read');
        $this->assertSame('<b>x</b>', $refuse->render('{{ html }}', ['html' => new Html('<b>x</b>')]));
        $this->assertStringContainsString('Internal', (new Renderer)->render('{{ record }}', ['record' => $record], false), 'without a guard, as before');
    }

    public function test_plain_text_passes_through(): void
    {
        $this->assertSame("Hello\n\n**world**", $this->render("Hello\n\n**world**"));
    }

    public function test_values_and_paths(): void
    {
        $data = [
            'ip' => '10.0.0.1',
            'invoice' => ['number' => 'INV-7', 'user' => ['name' => 'Ann']],
            'service' => new class
            {
                public string $name = 'VPS';

                private string $secret = 'no';
            },
        ];

        $this->assertSame('10.0.0.1 INV-7 Ann VPS', $this->render('{{ ip }} {{invoice.number}} {{ invoice.user.name }} {{ service.name }}', $data));
        $this->assertSame('[]', $this->render('[{{ service.secret }}]', $data), 'private properties are not readable');
        $this->assertSame('[]', $this->render('[{{ missing }}{{ invoice.nope.deeper }}]', $data));
    }

    public function test_array_access_objects_are_read_through_offsets(): void
    {
        $model = new \ArrayObject(['formattedTotal' => '€ 10.00']);

        $this->assertSame('€ 10.00', $this->render('{{ invoice.formattedTotal }}', ['invoice' => $model]));
    }

    public function test_values_are_html_escaped_unless_raw_or_html(): void
    {
        $data = ['name' => '<b>"x"</b>', 'safe' => new Html('<p>ok</p>')];

        $this->assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $this->render('{{ name }}', $data));
        $this->assertSame('<b>"x"</b>', $this->render('{{ name|raw }}', $data));
        $this->assertSame('<p>ok</p>', $this->render('{{ safe }}', $data));
        $this->assertSame('<b>"x"</b>', $this->render('{{ name }}', $data, escape: false));
    }

    public function test_blade_and_php_are_inert(): void
    {
        $template = '{!! phpinfo() !!} @php echo 1; @endphp <?php echo 2; ?> {{ $x }}';

        $this->expectException(TemplateError::class);
        $this->render($template, ['x' => 1]);
    }

    public function test_blade_directives_without_braces_stay_literal_text(): void
    {
        $this->assertSame('@if(true) <?php echo 2; ?> {!! phpinfo() !!}', $this->render('@if(true) <?php echo 2; ?> {!! phpinfo() !!}'));
    }

    public function test_filters(): void
    {
        $data = ['at' => new \DateTimeImmutable('2026-10-11 13:05'), 'empty' => '', 'name' => 'Ωμέγα'];

        $this->assertSame('October 11, 2026', $this->render('{{ at|date("F j, Y") }}', $data));
        $this->assertSame('2026-10-11 13:05', $this->render('{{ at }}', $data));
        $this->assertSame('n/a', $this->render("{{ empty|default('n/a') }}", $data));
        $this->assertSame('ΩΜΈΓΑ ωμέγα', $this->render('{{ name|upper }} {{ name|lower }}', $data));
    }

    public function test_if_else_not_and_comparisons(): void
    {
        $t = '{% if c.type == "end_of_period" %}end{% else %}now{% endif %}|{% if not c.reason %}none{% endif %}|{% if c.type != "x" %}diff{% endif %}';

        $this->assertSame('end|none|diff', $this->render($t, ['c' => ['type' => 'end_of_period', 'reason' => '']]));
        $this->assertSame('now||diff', $this->render($t, ['c' => ['type' => 'immediate', 'reason' => 'too slow']]));
    }

    public function test_truthiness(): void
    {
        $t = '{% if v %}y{% else %}n{% endif %}';

        foreach ([[null, 'n'], ['', 'n'], ['0', 'n'], [0, 'n'], [[], 'n'], [new \ArrayObject([]), 'n'], ['a', 'y'], [1, 'y'], [[1], 'y'], [new \ArrayObject([1]), 'y']] as [$value, $expected]) {
            $this->assertSame($expected, $this->render($t, ['v' => $value]), var_export($value, true));
        }
    }

    public function test_for_loop_builds_markdown_table_rows_without_blank_lines(): void
    {
        $template = <<<'MD'
            | Item | Qty |
            | :--: | :-: |
            {% for item in items %}
            | {{ item.description }} | {{ item.quantity }} |
            {% endfor %}
            Total: {{ total }}
            MD;
        $data = ['items' => new \ArrayIterator([['description' => 'Hosting', 'quantity' => 1], ['description' => 'Domain', 'quantity' => 2]]), 'total' => '€ 30'];

        $this->assertSame("| Item | Qty |\n| :--: | :-: |\n| Hosting | 1 |\n| Domain | 2 |\nTotal: € 30", $this->render($template, $data));
    }

    public function test_loop_variables_and_scoping(): void
    {
        $t = '{% for x in xs %}{{ loop.index }}{% if loop.first %}^{% endif %}{% if loop.last %}${% endif %}{{ x }}{{ name }},{% endfor %}{{ x }}';

        $this->assertSame('1^a!,2b!,3$c!,', $this->render($t, ['xs' => ['a', 'b', 'c'], 'name' => '!']));
        $this->assertSame('', $this->render('{% for x in nothing %}{{ x }}{% endfor %}'));
    }

    public function test_nested_blocks(): void
    {
        $t = '{% for s in services %}{% if s.active %}{% for a in s.addons %}{{ s.name }}:{{ a }} {% endfor %}{% else %}({{ s.name }}) {% endif %}{% endfor %}';
        $data = ['services' => [['name' => 'A', 'active' => true, 'addons' => ['x', 'y']], ['name' => 'B', 'active' => false, 'addons' => ['z']]]];

        $this->assertSame('A:x A:y (B) ', $this->render($t, $data));
    }

    public function test_custom_accessor_can_hide_fields(): void
    {
        $renderer = new Renderer(fn (mixed $target, string $key) => $key === 'password' ? null : Renderer::read($target, $key));

        $this->assertSame('ann:', $renderer->render('{{ user.name }}:{{ user.password }}', ['user' => ['name' => 'ann', 'password' => 'hash']]));
    }

    public static function brokenTemplates(): array
    {
        return [
            'function call' => ["{{ route('home') }}", 1],
            'php variable' => ['{{ $ip }}', 1],
            'method call' => ['{{ user.name() }}', 1],
            'unknown filter' => ['{{ x|system("id") }}', 1],
            'date without format' => ['{{ x|date }}', 1],
            'unknown tag' => ["a\n{% include 'x' %}", 2],
            'unclosed if' => ["\n\n{% if x %}y", 3],
            'stray endif' => ['{% endif %}', 1],
            'stray else' => ['{% else %}', 1],
            'endfor closes if' => ['{% if x %}{% endfor %}', 1],
            'bad for' => ['{% for in xs %}{% endfor %}', 1],
            'loop is reserved' => ['{% for loop in xs %}{% endfor %}', 1],
            'and is unsupported' => ['{% if a and b %}{% endif %}', 1],
            'empty output' => ['{{ }}', 1],
        ];
    }

    #[DataProvider('brokenTemplates')]
    public function test_syntax_errors_name_the_line(string $template, int $line): void
    {
        $this->expectException(TemplateError::class);
        $this->expectExceptionMessageMatches("/^Line {$line}: /");

        (new Renderer)->validate($template);
    }
}
