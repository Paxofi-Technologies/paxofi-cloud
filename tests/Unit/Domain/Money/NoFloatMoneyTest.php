<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Money;

use PHPUnit\Framework\TestCase;

/**
 * PRC-001; commerce threat model C-02: no float can enter money code. Scans
 * the token stream (not text), so comments and strings do not count. Add every
 * new money directory to MONEY_PATHS.
 */
final class NoFloatMoneyTest extends TestCase
{
    private const array MONEY_PATHS = ['src/Domain/Money'];

    /** Functions that return floats or format through floats. */
    private const array FORBIDDEN_FUNCTIONS = ['round', 'floor', 'ceil', 'floatval', 'fdiv', 'number_format', 'fmod', 'pow', 'sqrt'];

    public function testMoneyCodeHasNoFloats(): void
    {
        $root = dirname(__DIR__, 4);
        $files = 0;
        foreach (self::MONEY_PATHS as $path) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $files++;
                self::assertSame([], self::violations((string) file_get_contents($file->getPathname())), $file->getPathname());
            }
        }
        self::assertGreaterThan(0, $files);
    }

    public function testTheScannerCatchesEachForm(): void
    {
        self::assertNotSame([], self::violations('<?php function a(float $x) {}'));
        self::assertNotSame([], self::violations('<?php $a = 1.5;'));
        self::assertNotSame([], self::violations('<?php $a = (float) $b;'));
        self::assertNotSame([], self::violations('<?php $a = round($b);'));
        self::assertNotSame([], self::violations('<?php $a = $b / 100;'));
        self::assertSame([], self::violations('<?php // a float in a comment, 1.5' . "\n" . '$a = intdiv($b, 100);'));
    }

    /** @return list<string> */
    private static function violations(string $source): array
    {
        $found = [];
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if ($token === '/') {
                $found[] = 'division operator (use intdiv)';
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            [$id, $text] = $token;
            if ($id === T_DNUMBER || $id === T_DOUBLE_CAST || ($id === T_STRING && strtolower($text) === 'float')) {
                $found[] = $text;
            }
            if ($id === T_STRING && in_array(strtolower($text), self::FORBIDDEN_FUNCTIONS, true) && self::nextIsCall($tokens, $i)) {
                $found[] = $text . '()';
            }
        }

        return $found;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private static function nextIsCall(array $tokens, int $i): bool
    {
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            return $tokens[$j] === '(';
        }

        return false;
    }
}
