<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;

/**
 * Language support for the welcome / sign-in screen (English + Urdu).
 *
 * One catalog per language lives in app/Language/{lang}/Login.php. The server
 * renders from it and ships the same data to the browser, so switching
 * language is instant and never loses what the user has typed.
 *
 * Adding a language: add it to META, add app/Language/{code}/Login.php.
 * The tests fail until the new catalog has exactly the same keys.
 */
final class UiLanguage
{
    public const DEFAULT_LANG = 'en';
    public const COOKIE       = 'lpg_lang';

    /** dir = text direction; date/time = BCP-47 locales used by both PHP (ICU) and the browser (Intl). */
    private const META = [
        'en' => ['name' => 'English', 'dir' => 'ltr', 'date' => 'en-GB', 'time' => 'en-US'],
        'ur' => ['name' => 'اردو',    'dir' => 'rtl', 'date' => 'ur-PK', 'time' => 'ur-PK'],
    ];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    /** @var array<string, string>|null English message => catalog key */
    private static ?array $messageKeys = null;

    /** @var array<string, array<string, string>> */
    private static array $appDictionaries = [];

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::META);
    }

    /** Whitelist anything coming from a cookie or query string. */
    public static function resolve(?string $requested): string
    {
        return is_string($requested) && isset(self::META[$requested]) ? $requested : self::DEFAULT_LANG;
    }

    /** The visitor's language, from the (whitelisted) cookie. Safe to call anywhere in a request. */
    public static function fromRequest(): string
    {
        return self::resolve(service('request')->getCookie(self::COOKIE));
    }

    /** @return array{name:string,dir:string,date:string,time:string} */
    public static function meta(string $lang): array
    {
        return self::META[self::resolve($lang)];
    }

    /** @return array<string, string> */
    public static function catalog(string $lang): array
    {
        $lang = self::resolve($lang);

        return self::$catalogs[$lang] ??= (static function () use ($lang): array {
            $catalog = require APPPATH . 'Language/' . $lang . '/Login.php';

            return is_array($catalog) ? $catalog : [];
        })();
    }

    /**
     * Translate a key, replacing `{name}` placeholders. Falls back to English,
     * then to the key itself, so a missing string never breaks the page.
     *
     * @param array<string, scalar> $args
     */
    public static function t(string $lang, string $key, array $args = []): string
    {
        $text = self::catalog($lang)[$key] ?? self::catalog(self::DEFAULT_LANG)[$key] ?? $key;

        if ($args === []) {
            return $text;
        }

        $replace = [];
        foreach ($args as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    /**
     * Catalog key for a server-generated message ("msg_*" keys), matched by its
     * exact English text. Unknown or dynamic messages return null and are shown as-is.
     */
    public static function keyForMessage(string $message): ?string
    {
        if (self::$messageKeys === null) {
            self::$messageKeys = [];
            foreach (self::catalog(self::DEFAULT_LANG) as $key => $text) {
                if (str_starts_with($key, 'msg_')) {
                    self::$messageKeys[$text] = $key;
                }
            }
        }

        return self::$messageKeys[$message] ?? null;
    }

    /** Data the browser needs to switch language without a reload. */
    public static function clientPayload(string $lang): array
    {
        $catalogs = [];
        $locales  = [];
        foreach (self::supported() as $code) {
            $catalogs[$code] = self::catalog($code);
            $locales[$code]  = ['dir' => self::META[$code]['dir'], 'date' => self::META[$code]['date'], 'time' => self::META[$code]['time']];
        }

        return ['lang' => self::resolve($lang), 'fallback' => self::DEFAULT_LANG, 'catalog' => $catalogs, 'locales' => $locales];
    }

    /**
     * Current date and time in the chosen language (server-side fallback; the
     * browser takes over immediately and shows the visitor's own clock).
     *
     * @return array{date:string,time:string}
     */
    public static function now(string $lang, ?DateTimeInterface $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $meta = self::meta($lang);

        // Same styles as the browser's {dateStyle:'full'} / {timeStyle:'short'}, so the swap is invisible.
        $date = self::icu($meta['date'], IntlDateFormatter::FULL, IntlDateFormatter::NONE, $at);
        $time = self::icu($meta['time'], IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $at);

        return [
            'date' => $date ?? $at->format('l, j F Y'),
            'time' => $time ?? $at->format('g:i A'),
        ];
    }

    private static function icu(string $locale, int $dateStyle, int $timeStyle, DateTimeInterface $at): ?string
    {
        if (! class_exists(IntlDateFormatter::class)) {
            return null;
        }

        $formatter = new IntlDateFormatter(str_replace('-', '_', $locale), $dateStyle, $timeStyle, $at->getTimezone());
        $text      = $formatter->format($at);

        return $text === false ? null : $text;
    }

    // ---- whole-app dictionary ---------------------------------------------

    /**
     * English text => translation, merged from app/Language/{lang}/app/*.php.
     * English is the source language, so it needs no dictionary (returns []).
     * A key containing {0}, {1}... is a pattern; the numbers are the parts that
     * vary at runtime (amounts, names, counts).
     *
     * @return array<string, string>
     */
    public static function appDictionary(string $lang): array
    {
        $lang = self::resolve($lang);
        if ($lang === self::DEFAULT_LANG) {
            return [];
        }

        return self::$appDictionaries[$lang] ??= (static function () use ($lang): array {
            $merged = [];
            foreach (self::appDictionaryFiles($lang) as $file) {
                $part = require $file;
                foreach (is_array($part) ? $part : [] as $english => $translated) {
                    $merged[(string) $english] = (string) $translated;
                }
            }

            return $merged;
        })();
    }

    /** @return list<string> */
    public static function appDictionaryFiles(string $lang): array
    {
        $files = glob(APPPATH . 'Language/' . self::resolve($lang) . '/app/*.php') ?: [];
        sort($files);

        return $files;
    }

    /** Collapse whitespace the same way the browser translator does before matching. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Dictionary split for the browser: exact strings, and {n} patterns (most specific first).
     *
     * @return array{lang:string,exact:array<string,string>,patterns:list<array{0:string,1:string}>}
     */
    public static function appPayload(string $lang): array
    {
        $exact    = [];
        $patterns = [];
        foreach (self::appDictionary($lang) as $english => $translated) {
            if (preg_match('/\{\d+\}/', $english) === 1) {
                $patterns[] = [$english, $translated];
            } else {
                $exact[$english] = $translated;
            }
        }
        usort($patterns, static fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        return ['lang' => self::resolve($lang), 'exact' => $exact, 'patterns' => $patterns];
    }

    /** Changes whenever a dictionary file does, so browsers can cache the dictionary for a year. */
    public static function appVersion(string $lang): string
    {
        $stamp = '';
        foreach (self::appDictionaryFiles($lang) as $file) {
            $stamp .= basename($file) . filemtime($file);
        }

        return substr(md5($stamp), 0, 10);
    }
}
