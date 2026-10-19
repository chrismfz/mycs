<?php

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Everything says itself in Greek and English (CLAUDE.md; owner 2026-10-19): every key the code asks for exists in both
 * (a missing one shows its key, «invoices.apply_credit_and_continue», on the page), every plain string has its Greek, and
 * the Greek files hold every English key.
 */
class TranslationsCompleteTest extends TestCase
{
    /** @return array<string, string> key => the first file asking for it */
    private function used(): array
    {
        $used = [];
        foreach ([app_path(), resource_path('views'), base_path('extensions')] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!str_ends_with((string) $file, '.php')) {
                    continue;
                }
                preg_match_all("/(?:__|trans|trans_choice|@lang)\\(\\s*'((?:[^'\\\\]|\\\\.)+)'/", (string) file_get_contents((string) $file), $m);
                foreach ($m[1] as $key) {
                    $used[stripslashes($key)] ??= str_replace(base_path() . '/', '', (string) $file);
                }
            }
        }

        return $used;
    }

    public function test_every_key_asked_for_exists_in_greek_and_english(): void
    {
        $groups = array_map(fn ($f) => basename($f, '.php'), glob(lang_path('en/*.php')) ?: []);
        $greek = json_decode((string) file_get_contents(lang_path('el.json')), true);
        $missing = [];
        foreach ($this->used() as $key => $where) {
            // A key built at run time («domains.order.cart_» . $kind) is not one to look up.
            if (preg_match('/[._]$/', $key) === 1 || str_contains($key, '$')) {
                continue;
            }
            $grouped = preg_match('/^([a-z_\-]+)\.\S/', $key, $m) === 1 && in_array($m[1], $groups, true);
            if (str_contains($key, '::') && preg_match('#^(extensions/[^/]+/[^/]+)/#', $where, $ext) === 1) {
                // An extension's own (its namespace loads only while it is on): its files, both languages.
                [$namespace, $path] = explode('::', $key, 2);
                [$file, $item] = array_pad(explode('.', $path, 2), 2, '');
                foreach (['en', 'el'] as $locale) {
                    $lines = is_file($f = base_path("{$ext[1]}/resources/lang/{$locale}/{$file}.php")) ? require $f : [];
                    if (!is_array($lines) || !array_key_exists($item, $lines)) {
                        $missing[] = "{$locale}: {$key} ({$where})";
                    }
                }
            } elseif ($grouped || str_contains($key, '::')) {
                foreach (['en', 'el'] as $locale) {
                    if (!trans()->has($key, $locale, false)) {
                        $missing[] = "{$locale}: {$key} ({$where})";
                    }
                }
            } elseif (!array_key_exists($key, $greek)) {
                $missing[] = "el.json: {$key} ({$where})";
            }
        }

        $this->assertSame([], $missing, "Keys with no text:\n" . implode("\n", $missing));
    }

    public function test_the_greek_files_hold_every_english_key(): void
    {
        $flat = function (array $a, string $prefix = '') use (&$flat): array {
            $out = [];
            foreach ($a as $k => $v) {
                $out += is_array($v) ? $flat($v, "{$prefix}{$k}.") : ["{$prefix}{$k}" => true];
            }

            return $out;
        };
        $missing = [];
        foreach (glob(lang_path('en/*.php')) ?: [] as $file) {
            $name = basename($file);
            $el = is_file(lang_path("el/{$name}")) ? $flat(require lang_path("el/{$name}")) : [];
            foreach (array_keys(array_diff_key($flat(require $file), $el)) as $key) {
                $missing[] = basename($name, '.php') . ".{$key}";
            }
        }

        $this->assertSame([], $missing);
    }
}
