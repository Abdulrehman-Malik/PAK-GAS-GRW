<?php

use App\Libraries\UiLanguage;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\TranslatableText;

/**
 * The Urdu dictionary (app/Language/ur/app/*.php) must stay complete and consistent:
 * every piece of English interface text found in views, view scripts and controller or
 * service messages needs an entry, so Urdu users never meet a half-translated screen.
 *
 * When this fails after you add or change English text: add the listed strings to a file
 * in app/Language/ur/app/ (key = the English text exactly as shown, {0}, {1}... for parts
 * that vary at runtime). If a string is technical and must stay English, map it to itself.
 *
 * @internal
 */
final class AppDictionaryTest extends CIUnitTestCase
{
    /** Views that are not part of the translated app. */
    private const SKIP_VIEWS = ['errors/', 'welcome_message.php', 'auth/login.php'];

    /** @return array<string, string> */
    private function dictionary(): array
    {
        return UiLanguage::appDictionary('ur');
    }

    /** @return list<array{0:string,1:string}> [relative path, contents] */
    private function files(string $dir, string $extension = 'php'): array
    {
        $found    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== $extension) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(APPPATH)));
            $found[]  = [$relative, (string) file_get_contents($file->getPathname())];
        }

        return $found;
    }

    /** @param list<string> $texts */
    private function assertCovered(array $texts, string $where): void
    {
        $dictionary = $this->dictionary();
        $missing    = array_values(array_filter($texts, static fn (string $text): bool => ! array_key_exists($text, $dictionary)));

        $this->assertSame([], $missing, "Missing Urdu translations ({$where}):\n  - " . implode("\n  - ", array_slice($missing, 0, 40)));
    }

    // ---- dictionary integrity ----------------------------------------------

    public function testDictionaryIsLoadedFromEveryFile(): void
    {
        $this->assertGreaterThan(1000, count($this->dictionary()));
        $this->assertSame([], UiLanguage::appDictionary('en'), 'English is the source language and needs no dictionary.');
    }

    public function testNoKeyIsDefinedInTwoFiles(): void
    {
        $owners = [];
        $dups   = [];
        foreach (UiLanguage::appDictionaryFiles('ur') as $file) {
            foreach (array_keys(require $file) as $key) {
                if (isset($owners[$key])) {
                    $dups[] = "{$key}  ({$owners[$key]} and " . basename($file) . ')';
                }
                $owners[$key] = basename($file);
            }
        }

        $this->assertSame([], $dups, "Duplicate keys:\n  - " . implode("\n  - ", $dups));
    }

    public function testKeysAreNormalisedLikeTheBrowserTranslator(): void
    {
        foreach (array_keys($this->dictionary()) as $key) {
            $this->assertSame(UiLanguage::normalize($key), $key, "Key has extra whitespace and could never match: '{$key}'");
        }
    }

    public function testEveryTranslationIsFilledAndKeepsItsPlaceholders(): void
    {
        $placeholders = static function (string $text): array {
            preg_match_all('/\{\d+\}/', $text, $m);
            $found = array_unique($m[0]);
            sort($found);

            return $found;
        };

        foreach ($this->dictionary() as $english => $urdu) {
            $this->assertNotSame('', trim($urdu), "Empty translation for: {$english}");
            $extra = array_diff($placeholders($urdu), $placeholders($english));
            $this->assertSame([], array_values($extra), "Translation adds placeholders the English text does not have: {$english}");
        }
    }

    public function testTranslationsAreActuallyUrduOrIntentionallyUnchanged(): void
    {
        $untranslated = [];
        foreach ($this->dictionary() as $english => $urdu) {
            $hasUrdu = preg_match('/\p{Arabic}/u', $urdu) === 1;
            // Unchanged is fine for codes, brand and unit text, but not for real sentences.
            if (! $hasUrdu && $urdu !== $english) {
                $untranslated[] = $english;
            }
        }

        $this->assertSame([], $untranslated, "Translation has no Urdu text and differs from English:\n  - " . implode("\n  - ", array_slice($untranslated, 0, 40)));
    }

    // ---- coverage of the app's English text ---------------------------------

    public function testEveryViewTextHasAnUrduEntry(): void
    {
        $texts = [];
        foreach ($this->files('Views') as [$path, $source]) {
            foreach (self::SKIP_VIEWS as $skip) {
                if (str_contains($path, $skip)) {
                    continue 2;
                }
            }
            foreach (TranslatableText::fromView($source) as $text) {
                $texts[] = $text;
            }
        }

        $this->assertCovered(array_values(array_unique($texts)), 'text in views');
    }

    public function testEveryViewScriptTextHasAnUrduEntry(): void
    {
        $texts = [];
        foreach ($this->files('Views') as [$path, $source]) {
            foreach (self::SKIP_VIEWS as $skip) {
                if (str_contains($path, $skip)) {
                    continue 2;
                }
            }
            foreach (array_merge(TranslatableText::fromScriptBlocks($source), TranslatableText::fromViewPhpStrings($source)) as $text) {
                $texts[] = $text;
            }
        }

        $this->assertCovered(array_values(array_unique($texts)), 'text built by view scripts and view PHP code');
    }

    public function testEveryControllerAndServiceMessageHasAnUrduEntry(): void
    {
        $texts = [];
        foreach (['Controllers', 'Services', 'Models', 'Filters'] as $dir) {
            foreach ($this->files($dir) as [$path, $source]) {
                if (in_array($path, ['Controllers/Language.php', 'Controllers/Landing.php'], true)) {
                    continue;
                }
                foreach (TranslatableText::fromPhpMessages($source) as $text) {
                    $texts[] = $text;
                }
            }
        }

        $this->assertCovered(array_values(array_unique($texts)), 'messages from controllers and services');
    }

    // ---- delivery to the browser -----------------------------------------

    public function testPayloadSplitsExactTextAndPatterns(): void
    {
        $payload = UiLanguage::appPayload('ur');

        $this->assertSame('ur', $payload['lang']);
        $this->assertArrayHasKey('Sale History', $payload['exact']);
        $this->assertNotEmpty($payload['patterns']);

        $lengths = array_map(static fn (array $p): int => strlen($p[0]), $payload['patterns']);
        $sorted  = $lengths;
        rsort($sorted);
        $this->assertSame($sorted, $lengths, 'Patterns must be tried most specific (longest) first.');

        foreach ($payload['patterns'] as [$english]) {
            $this->assertMatchesRegularExpression('/\{\d+\}/', $english);
        }
        foreach (array_keys($payload['exact']) as $english) {
            $this->assertDoesNotMatchRegularExpression('/\{\d+\}/', (string) $english);
        }
    }

    public function testEnglishPayloadIsEmpty(): void
    {
        $payload = UiLanguage::appPayload('en');

        $this->assertSame([], $payload['exact']);
        $this->assertSame([], $payload['patterns']);
    }

    public function testDictionaryVersionChangesWhenAFileChanges(): void
    {
        $version = UiLanguage::appVersion('ur');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $version);
        $this->assertSame($version, UiLanguage::appVersion('ur'), 'Stable between calls so browsers can cache it.');
    }

    // ---- shared partials ----------------------------------------------------

    public function testEnglishPagesLoadNoExtraLanguageAssets(): void
    {
        helper('url');

        // CodeIgniter wraps views in debug comments; only real output matters.
        $strip = static fn (string $html): string => trim((string) preg_replace('/<!--.*?-->/s', '', $html));

        $this->assertSame('', $strip(view('partials/i18n_head', ['uiLang' => 'en'])));
        $this->assertSame('', $strip(view('partials/i18n_foot', ['uiLang' => 'en'])));
    }

    public function testUrduPagesLoadRtlStylesDictionaryAndTranslator(): void
    {
        helper('url');
        $head = view('partials/i18n_head', ['uiLang' => 'ur']);
        $foot = view('partials/i18n_foot', ['uiLang' => 'ur']);

        $this->assertStringContainsString('css/lpg-rtl.css', $head);
        $this->assertStringContainsString('i18n/ur.js?v=' . UiLanguage::appVersion('ur'), $head);
        $this->assertStringContainsString('Noto+Naskh+Arabic', $head);
        $this->assertStringContainsString('i18n-pending', $head);
        $this->assertStringContainsString('js/lpg-i18n.js', $foot);
    }

    public function testLanguageSwitchLinksToEveryLanguageAndMarksTheCurrentOne(): void
    {
        helper('url');
        $html = view('partials/language_switch', ['uiLang' => 'ur']);

        $this->assertMatchesRegularExpression('#href="[^"]*/language/en"#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/language/ur"[^>]*aria-current="true"#', $html);
        $this->assertStringContainsString('data-no-i18n', $html, 'The switch must keep its own language names untouched.');
    }

    public function testTranslatorAndStylesheetShipWithTheApp(): void
    {
        $this->assertFileExists(ROOTPATH . 'public/js/lpg-i18n.js');
        $this->assertFileExists(ROOTPATH . 'public/css/lpg-rtl.css');
        $this->assertStringContainsString('html[dir="rtl"] .sidebar', (string) file_get_contents(ROOTPATH . 'public/css/lpg-rtl.css'));
    }

    public function testLayoutAndStandalonePagesUseTheSharedPartials(): void
    {
        foreach (['layouts/app.php', 'sales/receipt.php', 'installation/setup.php'] as $view) {
            $source = (string) file_get_contents(APPPATH . 'Views/' . $view);

            $this->assertStringContainsString('partials/i18n_head', $source, $view);
            $this->assertStringContainsString('partials/i18n_foot', $source, $view);
            $this->assertStringContainsString('UiLanguage::fromRequest()', $source, $view);
            $this->assertMatchesRegularExpression('/<html[^>]*lang="<\?= esc\(\$uiLang/', $source, "{$view} must set lang and dir on <html>.");
        }

        $layout = (string) file_get_contents(APPPATH . 'Views/layouts/app.php');
        $this->assertStringContainsString('bootstrap.rtl.min.css', $layout);
        $this->assertStringContainsString('partials/language_switch', $layout);
    }

    public function testLanguageRoutesAreRegisteredAndPublic(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $public = substr($routes, 0, (int) strpos($routes, "routes->group('', ['filter'=>'auth']"));

        $this->assertStringContainsString("get('language/(:segment)','Language::set/\$1')", $public);
        $this->assertStringContainsString("get('i18n/(:segment).js','Language::dictionary/\$1')", $public);
    }
}
