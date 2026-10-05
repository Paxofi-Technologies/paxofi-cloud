<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Enforces ADR-001 layering (SRS NFR-011):
 *
 * - Domain is pure PHP: no framework, no infrastructure, no outer layers.
 * - Application may use PCF *contracts* only, never concrete PCF classes,
 *   and never PaxofiCloud's infrastructure, HTTP, CLI or bootstrap code.
 *
 * Names are read with PHP's tokenizer, so `use` imports and inline fully
 * qualified names are both checked, and comments or strings are ignored.
 */
final class LayeringTest extends TestCase
{
    private const array OUTER_LAYERS = [
        'PaxofiCloud\\Infrastructure\\',
        'PaxofiCloud\\Http\\',
        'PaxofiCloud\\Cli\\',
        'PaxofiCloud\\Bootstrap\\',
    ];

    public function testDomainDependsOnNothingOutsideTheDomain(): void
    {
        $violations = [];
        foreach ($this->phpFiles('src/Domain') as $file) {
            foreach (self::referencedNames((string) file_get_contents($file)) as $name) {
                if (str_starts_with($name, 'Paxofi\\') || (str_starts_with($name, 'PaxofiCloud\\') && !str_starts_with($name, 'PaxofiCloud\\Domain\\'))) {
                    $violations[] = $this->relative($file) . ' -> ' . $name;
                }
            }
        }

        self::assertSame([], $violations, 'Domain must be pure PHP (ADR-001).');
    }

    public function testApplicationUsesOnlyPcfContractsAndNoOuterLayers(): void
    {
        $violations = [];
        foreach ($this->phpFiles('src/Application') as $file) {
            foreach (self::referencedNames((string) file_get_contents($file)) as $name) {
                if (self::violatesApplicationRules($name)) {
                    $violations[] = $this->relative($file) . ' -> ' . $name;
                }
            }
        }

        self::assertSame([], $violations, 'Application may use Paxofi\\Core\\Contracts only (ADR-001).');
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function samples(): iterable
    {
        yield 'use import' => ["<?php\nuse Paxofi\\Core\\Http\\Request;\n", ['Paxofi\\Core\\Http\\Request']];
        yield 'grouped import' => ["<?php\nuse PaxofiCloud\\Http\\{Kernel, Router};\n", ['PaxofiCloud\\Http\\Kernel', 'PaxofiCloud\\Http\\Router']];
        yield 'inline fully qualified' => ["<?php\n\$r = new \\Paxofi\\Core\\Http\\Request('GET', '/');\n", ['Paxofi\\Core\\Http\\Request']];
        yield 'aliased import' => ["<?php\nuse PaxofiCloud\\Infrastructure\\Container\\Resolve as R;\n", ['PaxofiCloud\\Infrastructure\\Container\\Resolve']];
        yield 'comments and strings ignored' => ["<?php\n// use Paxofi\\Core\\Http\\Request;\n\$s = 'Paxofi\\\\Core\\\\Http';\n", []];
    }

    /** @param list<string> $expected */
    #[DataProvider('samples')]
    public function testTheCheckerSeesEveryFormOfReference(string $source, array $expected): void
    {
        self::assertSame($expected, self::referencedNames($source));
    }

    public function testApplicationRulesRejectConcretePcfAndOuterLayers(): void
    {
        self::assertTrue(self::violatesApplicationRules('Paxofi\\Core\\Http\\Request'));
        self::assertTrue(self::violatesApplicationRules('PaxofiCloud\\Infrastructure\\Persistence\\Row'));
        self::assertFalse(self::violatesApplicationRules('Paxofi\\Core\\Contracts\\Logger'));
        self::assertFalse(self::violatesApplicationRules('PaxofiCloud\\Domain\\Tenant\\TenantId'));
    }

    private static function violatesApplicationRules(string $name): bool
    {
        if (str_starts_with($name, 'Paxofi\\') && !str_starts_with($name, 'Paxofi\\Core\\Contracts\\')) {
            return true;
        }
        foreach (self::OUTER_LAYERS as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fully qualified class/namespace names referenced by imports or inline.
     *
     * @return list<string>
     */
    private static function referencedNames(string $source): array
    {
        $tokens = token_get_all($source);
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAME_FULLY_QUALIFIED) {
                $names[] = ltrim($token[1], '\\');
                continue;
            }

            if ($token[0] !== T_USE) {
                continue;
            }

            // `use A\B;`, `use A\B as C;`, `use A\{B, C as D};` (skip closure `use (` and trait use inside classes).
            $prefix = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if ($t === '(' || $t === ';') {
                    break;
                }
                if (is_array($t) && ($t[0] === T_NAME_QUALIFIED || $t[0] === T_NAME_FULLY_QUALIFIED || $t[0] === T_STRING)) {
                    $prev = self::previousSignificant($tokens, $j);
                    if ($prev === T_AS) {
                        continue;
                    }
                    $name = ltrim($t[1], '\\');
                    $next = self::nextSignificant($tokens, $j);
                    if ($next === T_NS_SEPARATOR) {
                        $prefix = $name . '\\';
                        continue;
                    }
                    $names[] = $prefix . $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function previousSignificant(array $tokens, int $index): int|string|null
    {
        for ($k = $index - 1; $k >= 0; $k--) {
            $t = $tokens[$k];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($t) ? $t[0] : $t;
        }

        return null;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function nextSignificant(array $tokens, int $index): int|string|null
    {
        $count = count($tokens);
        for ($k = $index + 1; $k < $count; $k++) {
            $t = $tokens[$k];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($t) ? $t[0] : $t;
        }

        return null;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $root = dirname(__DIR__, 3) . '/' . $directory;
        self::assertDirectoryExists($root);
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertNotEmpty($files, $directory . ' contains no PHP files; the layering check would be vacuous.');

        return $files;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen(dirname(__DIR__, 3)) + 1);
    }
}
