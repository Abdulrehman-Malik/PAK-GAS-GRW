<?php

use App\Libraries\UiLanguage;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * English / Urdu support for the welcome screen: catalog integrity, lookup rules,
 * server rendering, and that the browser payload cannot break out of its script tag.
 *
 * @internal
 */
final class UiLanguageTest extends CIUnitTestCase
{
    private function render(string $lang, array $data = []): string
    {
        helper(['form', 'url']);

        return view('auth/login', array_merge([
            'title'         => 'Login',
            'companyName'   => 'Test LPG',
            'migrationGate' => ['ready' => true, 'blocked_message' => null, 'migrations' => [], 'total' => 0, 'completed' => 0],
            'shop'          => [],
            'lang'          => $lang,
        ], $data));
    }

    private function placeholders(string $text): array
    {
        preg_match_all('/\{(\w+)\}/', $text, $m);
        sort($m[1]);

        return $m[1];
    }

    // ---- catalogs ---------------------------------------------------------

    public function testEveryLanguageHasExactlyTheSameKeys(): void
    {
        $reference = array_keys(UiLanguage::catalog('en'));
        sort($reference);

        foreach (UiLanguage::supported() as $lang) {
            $keys = array_keys(UiLanguage::catalog($lang));
            sort($keys);
            $this->assertSame($reference, $keys, "Catalog '{$lang}' must have the same keys as English (missing or extra keys).");
        }
    }

    public function testNoTranslationIsEmptyAndPlaceholdersMatchEnglish(): void
    {
        foreach (UiLanguage::supported() as $lang) {
            foreach (UiLanguage::catalog($lang) as $key => $text) {
                $this->assertIsString($text, "{$lang}.{$key}");
                $this->assertNotSame('', trim($text), "{$lang}.{$key} is empty");
                $this->assertSame($this->placeholders(UiLanguage::catalog('en')[$key]), $this->placeholders($text), "{$lang}.{$key} placeholders differ from English");
            }
        }
    }

    public function testUrduCatalogIsActuallyTranslated(): void
    {
        $en = UiLanguage::catalog('en');
        $ur = UiLanguage::catalog('ur');

        // Keys that are intentionally identical in both languages (none today).
        foreach ($en as $key => $text) {
            $this->assertNotSame($text, $ur[$key], "ur.{$key} is still English");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ur[$key], "ur.{$key} has no Urdu text");
        }
    }

    // ---- lookup -----------------------------------------------------------

    public function testResolveOnlyAcceptsSupportedLanguages(): void
    {
        $this->assertSame('ur', UiLanguage::resolve('ur'));
        $this->assertSame('en', UiLanguage::resolve('en'));

        foreach ([null, '', 'fr', 'UR', 'ur ', '../../etc/passwd', 'ur"><script>'] as $bad) {
            $this->assertSame('en', UiLanguage::resolve($bad), 'Unsupported value must fall back to English.');
        }
    }

    public function testTranslateFallsBackToEnglishThenKey(): void
    {
        $this->assertSame('Sign in', UiLanguage::t('en', 'signin_title'));
        $this->assertSame('لاگ اِن', UiLanguage::t('ur', 'signin_title'));
        $this->assertSame('Sign in', UiLanguage::t('xx', 'signin_title'), 'Unknown language uses English.');
        $this->assertSame('no_such_key', UiLanguage::t('ur', 'no_such_key'), 'Unknown key returns the key, never an error.');
    }

    public function testPlaceholdersAreReplaced(): void
    {
        $this->assertSame('25 of 26 migrations completed.', UiLanguage::t('en', 'gate_progress', ['done' => 25, 'total' => 26]));
        $this->assertStringContainsString('26', UiLanguage::t('ur', 'gate_progress', ['done' => 25, 'total' => 26]));
        $this->assertStringNotContainsString('{', UiLanguage::t('ur', 'gate_progress', ['done' => 25, 'total' => 26]));
    }

    public function testLanguageDirections(): void
    {
        $this->assertSame('ltr', UiLanguage::meta('en')['dir']);
        $this->assertSame('rtl', UiLanguage::meta('ur')['dir']);
        $this->assertSame('ltr', UiLanguage::meta('garbage')['dir']);
    }

    public function testNowReturnsDateAndTimeForEveryLanguage(): void
    {
        $at = new DateTimeImmutable('2026-10-16 16:19:00');

        $en = UiLanguage::now('en', $at);
        $this->assertStringContainsString('Friday', $en['date']);
        $this->assertStringContainsString('2026', $en['date']);
        $this->assertMatchesRegularExpression('/^4:19\s?PM$/u', $en['time']);

        $ur = UiLanguage::now('ur', $at);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ur['date']);
        $this->assertNotSame('', $ur['time']);
    }

    // ---- server messages --------------------------------------------------

    public function testControllerMessagesHaveTranslations(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Auth.php');
        preg_match_all("/->with\\('(?:error|success|migration_error)','([^'\$]+)'\\)/", $source, $m);

        $this->assertNotEmpty($m[1], 'Expected to find static flash messages in Auth.php.');
        foreach (array_unique($m[1]) as $message) {
            $this->assertNotNull(UiLanguage::keyForMessage($message), "Add a msg_* translation for: {$message}");
        }
    }

    public function testKeyForMessageIgnoresUnknownAndDynamicText(): void
    {
        $this->assertSame('msg_invalid_login', UiLanguage::keyForMessage('Invalid username/email or password.'));
        $this->assertNull(UiLanguage::keyForMessage('Migration 012_x.sql failed. Fix the database error, then execute migrations again.'));
        $this->assertNull(UiLanguage::keyForMessage('Sign in'), 'Only msg_* keys are matched.');
    }

    // ---- rendering --------------------------------------------------------

    public function testEnglishPageIsLeftToRightAndUntouched(): void
    {
        $html = $this->render('en');

        $this->assertStringContainsString('<html lang="en" dir="ltr">', $html);
        $this->assertStringContainsString('Every cylinder', $html);
        $this->assertStringContainsString('data-i18n="signin_title"', $html);
    }

    public function testUrduPageIsRightToLeftAndFullyTranslated(): void
    {
        $html = $this->render('ur');

        $this->assertStringContainsString('<html lang="ur" dir="rtl">', $html);
        $this->assertStringContainsString('ہر سلنڈر', $html);
        $this->assertStringContainsString('صارف نام یا ای میل', $html);
        $this->assertStringNotContainsString('>Username or Email<', $html);
        $this->assertStringNotContainsString('>Keep me signed in<', $html);
    }

    public function testInputsStayLeftToRightInUrdu(): void
    {
        $html = $this->render('ur');

        $this->assertMatchesRegularExpression('/id="login"[^>]*dir="ltr"/', $html);
        $this->assertMatchesRegularExpression('/id="password"[^>]*dir="ltr"/', $html);
    }

    public function testUnsupportedLanguageRendersEnglish(): void
    {
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $this->render('klingon'));
    }

    public function testLanguageSwitchOffersEveryLanguage(): void
    {
        $html = $this->render('en');

        $this->assertMatchesRegularExpression('/data-lang="en"[^>]*aria-pressed="true">English</', $html);
        $this->assertMatchesRegularExpression('/data-lang="ur"[^>]*aria-pressed="false">اردو</u', $html);
    }

    public function testClientPayloadCarriesBothCatalogsAndIsScriptSafe(): void
    {
        $html = $this->render('ur', ['shop' => ['name' => '</script><script>alert(1)</script>']]);

        preg_match('#<script type="application/json" id="wl-i18n">(.*?)</script>#s', $html, $m);
        $payload = json_decode($m[1] ?? '', true);

        $this->assertIsArray($payload, 'Payload must be valid JSON.');
        $this->assertSame('ur', $payload['lang']);
        $this->assertSame(UiLanguage::catalog('en'), $payload['catalog']['en']);
        $this->assertSame(UiLanguage::catalog('ur'), $payload['catalog']['ur']);
        $this->assertSame('rtl', $payload['locales']['ur']['dir']);
        $this->assertStringNotContainsString('<script>alert(1)', $html, 'Dynamic shop data must never create a script tag.');
    }

    public function testKnownServerMessageIsTranslatedAndUnknownIsKept(): void
    {
        $session = session();

        $session->setFlashdata('error', 'Invalid username/email or password.');
        $known = $this->render('ur');
        $this->assertStringContainsString('data-i18n="msg_invalid_login"', $known);
        $this->assertStringContainsString('غلط ہے', $known);

        $session->setFlashdata('error', 'Something <b>unexpected</b> happened.');
        $unknown = $this->render('ur');
        $this->assertStringContainsString('Something &lt;b&gt;unexpected&lt;/b&gt; happened.', $unknown);
        $this->assertStringNotContainsString('<b>unexpected</b>', $unknown);
    }

    public function testMigrationGateIsTranslatedWithCounts(): void
    {
        $html = $this->render('ur', ['migrationGate' => [
            'ready'           => false,
            'blocked_message' => 'Pending database migrations must be executed before users can sign in.',
            'migrations'      => [['seq' => 26, 'file' => '026_example.sql', 'status' => 'pending', 'message' => '']],
            'total'           => 26,
            'completed'       => 25,
        ]]);

        $this->assertStringContainsString('زیرِ التوا مائیگریشنز چلائیں', $html);
        $this->assertStringContainsString('data-busy="gate_busy"', $html);
        // The attribute value is entity-escaped; browsers decode it back to this JSON.
        $this->assertMatchesRegularExpression('/data-i18n-args="([^"]*)"/', $html);
        preg_match('/data-i18n-args="([^"]*)"/', $html, $m);
        $this->assertSame(['done' => 25, 'total' => 26], json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true));
        $this->assertStringContainsString('026_example.sql', $html, 'Technical file names stay as-is.');
    }
}
