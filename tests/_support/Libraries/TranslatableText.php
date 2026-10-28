<?php

namespace Tests\Support\Libraries;

/**
 * Finds the English interface text that needs an Urdu dictionary entry.
 *
 * Text that varies at runtime becomes {0}, {1}... so the result can be looked up
 * directly in app/Language/ur/app/*.php (same pattern syntax as the browser translator).
 *
 *  - fromView():          static text and translatable attributes of a PHP view
 *  - fromScriptBlocks():  text inside HTML strings that a view's <script> builds
 *  - fromPhpMessages():   flash/exception/JSON messages in controllers and services
 */
final class TranslatableText
{
    private const MARK_ECHO = "\x01";
    private const MARK_CODE = "\x02";

    /** @return list<string> */
    public static function fromView(string $source): array
    {
        $source = self::stripNonText($source);
        $found  = [];

        // Text between tags.
        foreach (self::splitTags($source) as $segment) {
            foreach (explode(self::MARK_CODE, $segment) as $piece) {
                self::add($found, $piece);
            }
        }

        // Translatable attributes.
        preg_match_all('/\b(?:placeholder|title|aria-label|alt)\s*=\s*"([^"]*)"/i', $source, $m);
        foreach ($m[1] as $value) {
            self::add($found, $value);
        }
        preg_match_all('/<input\b[^>]*\btype\s*=\s*"(?:submit|button)"[^>]*\bvalue\s*=\s*"([^"]*)"/i', $source, $m);
        foreach ($m[1] as $value) {
            self::add($found, $value);
        }

        return array_values(array_unique($found));
    }

    /**
     * Display text that a view keeps inside PHP code (menu labels, status names, ternaries):
     * string literals that read like a label or a sentence.
     *
     * @return list<string>
     */
    public static function fromViewPhpStrings(string $source): array
    {
        $found = [];
        $php   = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $php .= ' ; ';

                continue;
            }
            $php .= is_array($token) ? $token[1] : $token;
        }

        foreach (token_get_all('<?php ' . $php) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $text = self::unquote($token[1]);
            if (strlen($text) >= 3 && preg_match('/^[A-Z][A-Za-z0-9 &\/\-\.\',()—·:]*$/', $text) && preg_match('/[a-z]{2}/', $text) && ! preg_match('/^[A-Z][a-z]+(?:[A-Z][a-z]+)+$/', $text)) {
                self::add($found, $text);
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    public static function fromScriptBlocks(string $source): array
    {
        $found = [];
        preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $source, $blocks);

        foreach ($blocks[1] as $js) {
            $js = (string) preg_replace('#<\?(?:php|=).*?\?>#s', self::MARK_ECHO, $js);
            $js = (string) preg_replace('#/\*.*?\*/#s', ' ', $js);
            $js = (string) preg_replace('#(?<![:\'"\\\\])//[^\n]*#', ' ', $js);

            preg_match_all('/(?<![\w$])(?:\'((?:[^\'\\\\\n]|\\\\.)*)\'|"((?:[^"\\\\\n]|\\\\.)*)"|`((?:[^`\\\\]|\\\\.)*)`)/s', $js, $literals, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($literals as $match) {
                $literal = array_map(static fn (array $group): string => $group[0], $match);
                $before  = rtrim(substr($js, 0, $match[0][1]));
                $after   = ltrim(substr($js, $match[0][1] + strlen($match[0][0])));
                $text    = ($literal[1] ?? '') !== '' ? $literal[1] : (($literal[2] ?? '') !== '' ? $literal[2] : ($literal[3] ?? ''));
                $text = str_replace(["\\'", '\\"', '\\n'], ["'", '"', ' '], $text);
                $text = (string) preg_replace('/\$\{[^}]*\}/', self::MARK_ECHO, $text);

                if (str_contains($text, '<') || str_contains($text, '>')) {
                    // A literal is often one piece of a longer HTML string: drop a dangling
                    // tag start ("<td class=") and a dangling tag end ("\">Text").
                    $html = (string) preg_replace('/<[^>]*$/', '', $text);
                    $html = (string) preg_replace('/^[^<]*>/', '', $html);
                    foreach (self::splitTags($html) as $segment) {
                        self::addScriptText($found, $segment);
                    }
                    preg_match_all('/\b(?:placeholder|title|aria-label|alt)\s*=\s*\\\\?"([^"\\\\]*)/i', $text, $attrs);
                    foreach ($attrs[1] as $value) {
                        self::add($found, $value);
                    }
                } elseif (self::looksLikeMessage($text)) {
                    // 'Total: ' + amount  ->  "Total: {0}"  (the whole sentence reaches alert() or the DOM at runtime)
                    $text = (str_ends_with($before, '+') ? self::MARK_ECHO : '') . $text . (str_starts_with($after, '+') ? self::MARK_ECHO : '');
                    self::addScriptText($found, $text);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    public static function fromPhpMessages(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
        ));
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // new SomethingException('message'
            if (is_array($token) && $token[0] === T_NEW && isset($tokens[$i + 1]) && is_array($tokens[$i + 1])
                && str_ends_with($tokens[$i + 1][1], 'Exception') && ($tokens[$i + 2] ?? null) === '(') {
                self::collect($tokens, $i + 3, $found);
            }

            // ->with('error'|'success'|..., 'message'   and   ->fail('message'
            if (is_array($token) && $token[0] === T_STRING && ($tokens[$i - 1] ?? null) !== null && ($tokens[$i + 1] ?? null) === '(') {
                if ($token[1] === 'with' && is_array($tokens[$i + 2] ?? null) && ($tokens[$i + 3] ?? null) === ',') {
                    self::collect($tokens, $i + 4, $found);
                } elseif (in_array($token[1], ['fail', 'failNotFound', 'failForbidden', 'failValidationError'], true)) {
                    self::collect($tokens, $i + 2, $found);
                }
            }

            // sprintf('Message %s', ...)
            if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'sprintf' && ($tokens[$i + 1] ?? null) === '(') {
                self::collect($tokens, $i + 2, $found);
            }

            // 'message' => '...'   'error' => '...'   'msg' => '...'
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                && in_array(trim($token[1], '\'"'), ['message', 'error', 'msg', 'title', 'label', 'notes', 'note', 'description', 'reason', 'narration', 'remarks'], true)
                && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
                self::collect($tokens, $i + 2, $found);
            }
        }

        return array_values(array_unique($found));
    }

    // ------------------------------------------------------------------

    /** Remove everything that is not visible text, and mark PHP so it can be split or turned into {n}. */
    private static function stripNonText(string $source): string
    {
        $source = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $source);
        $source = (string) preg_replace('/<!--.*?-->/s', ' ', $source);
        $source = (string) preg_replace('#<\?php\s+/\*.*?\*/\s*\?>#s', ' ', $source);
        $source = (string) preg_replace('/<\?=.*?\?>/s', self::MARK_ECHO, $source);

        return (string) preg_replace('/<\?php.*?\?>/s', self::MARK_CODE, $source);
    }

    /** @return list<string> text between tags */
    private static function splitTags(string $source): array
    {
        return preg_split('/<[^>]*>/', $source) ?: [];
    }

    /** Text taken from a JavaScript string: reject anything that is still code. */
    private static function addScriptText(array &$found, string $text): void
    {
        if (preg_match('/[<>={};]|=>|function|onclick|class|style/', $text) === 1) {
            return;
        }

        self::add($found, $text);
    }

    private static function add(array &$found, string $text): void
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
        $text = trim($text);

        // Number the runtime parts in order of appearance.
        $n    = 0;
        $text = (string) preg_replace_callback('/\x01+/', static function () use (&$n): string {
            return '{' . $n++ . '}';
        }, $text);
        $text = trim(str_replace(self::MARK_CODE, '', $text));

        if (! self::hasWords($text)) {
            return;
        }

        $found[] = $text;
    }

    /** Needs at least two consecutive letters outside placeholders to be real text. */
    private static function hasWords(string $text): bool
    {
        return preg_match('/[A-Za-z]{2,}/', (string) preg_replace('/\{\d+\}/', '', $text)) === 1;
    }

    private static function looksLikeMessage(string $text): bool
    {
        $text = trim(str_replace(self::MARK_ECHO, '{0}', $text));

        if (strlen($text) < 4 || ! preg_match('/^[A-Z]/', $text) || preg_match('/[#=\/\\\\<>_]|^[A-Z0-9 ]{1,6}$/', $text)) {
            return false;
        }

        // A phrase (has a space) or a single capitalised word.
        return str_contains($text, ' ') || preg_match('/^[A-Z][a-z]{3,}$/', $text) === 1;
    }

    /** Build one message from the tokens of an expression, starting at $from. */
    private static function collect(array $tokens, int $from, array &$found): void
    {
        $depth = 0;
        $parts = [];            // list of ['lit'|'var', text]
        $hasTernary = false;

        for ($j = $from, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];

            if ($t === '(' || $t === '[') {
                $depth++;
            } elseif ($t === ')' || $t === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif (($t === ',' || $t === ';') && $depth === 0) {
                break;
            }

            if ($t === '?' && $depth === 0) {
                $hasTernary = true;
            }

            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $parts[] = ['lit', self::unquote($t[1]), $depth];
            } elseif ($t !== '.' && $t !== '(' && $t !== ')') {
                $parts[] = ['var', '', $depth];
            }
        }

        if ($hasTernary) {
            foreach ($parts as [$kind, $text]) {
                if ($kind === 'lit') {
                    self::addPhpLiteral($found, $text);
                }
            }

            return;
        }

        // Literals nested inside brackets or calls are array keys or arguments, not message text.
        $message = '';
        $pending = false;
        foreach ($parts as [$kind, $text, $partDepth]) {
            if ($partDepth > 0) {
                $pending = $pending || $kind === 'var';
            } elseif ($kind === 'lit') {
                $message .= ($pending ? self::MARK_ECHO : '') . $text;
                $pending = false;
            } else {
                $pending = true;
            }
        }
        if ($pending) {
            $message .= self::MARK_ECHO;
        }

        self::addPhpLiteral($found, $message);
    }

    private static function addPhpLiteral(array &$found, string $text): void
    {
        $text = (string) preg_replace('/%(?:\d\$)?[.\d]*[sdfF]/', self::MARK_ECHO, $text);
        $text = (string) preg_replace('/\{?\$[A-Za-z_]\w*(?:->\w+|\[[^\]]*\])*\}?/', self::MARK_ECHO, $text);
        $text = trim($text);

        // Only real sentences: starts with a capital letter, not a code/identifier string.
        if ($text === '' || ! preg_match('/^[A-Z]/', ltrim(str_replace(self::MARK_ECHO, '', $text)))
            || preg_match('/[<>=_]|\bSELECT\b|\bFROM\b/', $text)) {
            return;
        }

        self::add($found, $text);
    }

    private static function unquote(string $literal): string
    {
        $quote = $literal[0];
        $body  = substr($literal, 1, -1);

        return $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
    }
}
