<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

/**
 * Browser, OS and device type read from a User-Agent string (threat model
 * §6.14). Best effort and spoofable (T-50): it describes what the client
 * reported and is never used for a security decision. Order matters: Edge,
 * Opera and Samsung Internet also claim to be Chrome; Chrome claims Safari.
 */
final readonly class DeviceSummary
{
    public const string UNKNOWN = 'Unknown';

    private const array BROWSERS = [
        ['Edge', '/\bEdg(?:e|A|iOS)?\/(\d+)/'],
        ['Opera', '/\b(?:OPR|Opera)\/(\d+)/'],
        ['Samsung Internet', '/\bSamsungBrowser\/(\d+)/'],
        ['Firefox', '/\b(?:Firefox|FxiOS)\/(\d+)/'],
        ['Chrome', '/\b(?:Chrome|CriOS)\/(\d+)/'],
        ['Safari', '/\bVersion\/(\d+)[\d.]*(?: Mobile\/\S+)? Safari\//'],
    ];

    public function __construct(
        public string $browser,
        public string $browserVersion,
        public string $os,
        public string $osVersion,
        public string $deviceType,
    ) {
    }

    public static function fromUserAgent(string $userAgent): self
    {
        $ua = substr($userAgent, 0, 512);
        if ($ua === '') {
            return new self(self::UNKNOWN, '', self::UNKNOWN, '', 'unknown');
        }
        if (preg_match('/bot\b|crawl|spider|slurp|curl\/|wget\/|python-|go-http-client|okhttp|java\//i', $ua) === 1) {
            return new self('Automated client', '', self::UNKNOWN, '', 'bot');
        }

        [$browser, $browserVersion] = [self::UNKNOWN, ''];
        foreach (self::BROWSERS as [$name, $pattern]) {
            if (preg_match($pattern, $ua, $m) === 1) {
                [$browser, $browserVersion] = [$name, $m[1]];
                break;
            }
        }

        [$os, $osVersion] = self::os($ua);
        $deviceType = match (true) {
            str_contains($ua, 'iPad'), $os === 'Android' && !str_contains($ua, 'Mobile') => 'tablet',
            str_contains($ua, 'iPhone'), str_contains($ua, 'Mobile') => 'mobile',
            $os === self::UNKNOWN => 'unknown',
            default => 'desktop',
        };

        return new self($browser, substr($browserVersion, 0, 16), $os, substr($osVersion, 0, 16), $deviceType);
    }

    /** e.g. "Chrome 129 on Windows (desktop)". */
    public function label(): string
    {
        $browser = trim($this->browser . ' ' . $this->browserVersion);
        $os = trim($this->os . ' ' . $this->osVersion);

        return sprintf('%s on %s (%s)', $browser, $os, $this->deviceType);
    }

    /** @return array{string, string} */
    private static function os(string $ua): array
    {
        return match (true) {
            preg_match('/\b(?:iPhone|iPod)\b.*? OS (\d+)[_.](\d+)/', $ua, $m) === 1 => ['iOS', $m[1] . '.' . $m[2]],
            preg_match('/\biPad\b.*? OS (\d+)[_.](\d+)/', $ua, $m) === 1 => ['iPadOS', $m[1] . '.' . $m[2]],
            preg_match('/\bAndroid (\d+(?:\.\d+)?)/', $ua, $m) === 1 => ['Android', $m[1]],
            str_contains($ua, 'Android') => ['Android', ''],
            str_contains($ua, 'CrOS') => ['ChromeOS', ''],
            str_contains($ua, 'Windows NT') => ['Windows', ''],
            preg_match('/Mac OS X (\d+)[_.](\d+)/', $ua, $m) === 1 => ['macOS', $m[1] . '.' . $m[2]],
            str_contains($ua, 'Macintosh') => ['macOS', ''],
            str_contains($ua, 'Linux') => ['Linux', ''],
            default => [self::UNKNOWN, ''],
        };
    }
}
