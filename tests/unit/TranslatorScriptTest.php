<?php

use App\Libraries\UiLanguage;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Runs the real browser translator (public/js/lpg-i18n.js) in Node with the real Urdu
 * dictionary, so the matching rules are tested exactly as shipped. Skipped where Node is missing.
 *
 * @internal
 */
final class TranslatorScriptTest extends CIUnitTestCase
{
    /** @var array{results: array<string,string>, alerts: list<string>, revealed: bool}|null */
    private static ?array $run = null;

    /** Every case the tests below look at, translated in one Node run. */
    private const INPUTS = [
        'Sale History',
        "  Sale \n  History  ",
        'Cash session: OPEN — REG-01',
        'Customer Ledger — Ali Traders',
        'Manage users',
        'Showing 1 to 10 of 56 entries',
        'Date: activate to sort column descending',
        'C6 — 6 kg — Available Stock: 31.00 KG (6 cylinders)',
        'Stock (6 cylinders)',
        'Dashboard — Main LPG Shop',
        'This sentence is not in the dictionary at all.',
        'سیل ہسٹری',
        'Rs. 1,250.00',
    ];

    /**
     * Text already on the page when the translator starts. Several of these map to
     * themselves (units, "{0} — {1}" with untranslatable parts): they must not be rewritten.
     */
    private const PAGE_TEXT = ['KG', 'Perfect LPG', 'Alpha — Beta', '  Sale History  ', 'Already اردو'];

    protected function setUp(): void
    {
        parent::setUp();

        exec('node --version 2>&1', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Node.js is not available.');
        }

        if (self::$run === null) {
            $payload = json_encode(
                ['payload' => UiLanguage::appPayload('ur'), 'inputs' => self::INPUTS, 'nodes' => self::PAGE_TEXT],
                JSON_UNESCAPED_UNICODE
            );
            $script  = escapeshellarg(ROOTPATH . 'tests/js/translator.check.mjs');
            $process = proc_open('node ' . $script, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], (string) $payload);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($process);

            $decoded = json_decode((string) $stdout, true);
            $this->assertIsArray($decoded, "Translator run failed: {$stderr}");
            self::$run = $decoded;
        }
    }

    private function t(string $english): string
    {
        return self::$run['results'][$english];
    }

    public function testExactTextIsTranslated(): void
    {
        $this->assertSame('فروخت کی ہسٹری', $this->t('Sale History'));
    }

    public function testWhitespaceIsCollapsedBeforeMatching(): void
    {
        $this->assertSame($this->t('Sale History'), $this->t("  Sale \n  History  "));
    }

    public function testPatternsKeepTheVaryingPart(): void
    {
        $this->assertSame('کیش سیشن: کھلا ہے — REG-01', $this->t('Cash session: OPEN — REG-01'));
    }

    public function testVaryingPartsAreTranslatedToo(): void
    {
        $this->assertSame('ڈیش بورڈ — Main LPG Shop', $this->t('Dashboard — Main LPG Shop'));
        $this->assertStringStartsWith('کسٹمر لیجر — ', $this->t('Customer Ledger — Ali Traders'));
        $this->assertStringEndsWith('Ali Traders', $this->t('Customer Ledger — Ali Traders'));
    }

    public function testExactEntryBeatsAMatchingPattern(): void
    {
        // "{0} user{1}" would also match this; the exact entry must win.
        $this->assertSame('صارفین کا نظم کریں', $this->t('Manage users'));
    }

    public function testTableTextFromDataTablesIsTranslated(): void
    {
        $this->assertSame('56 میں سے 1 تا 10 اندراجات دکھائے جا رہے ہیں', $this->t('Showing 1 to 10 of 56 entries'));
        $sortLabel = $this->t('Date: activate to sort column descending');
        $this->assertStringContainsString('نزولی', $sortLabel);
        $this->assertStringStartsWith('تاریخ:', $sortLabel, 'The column name inside the label is translated as well.');
    }

    public function testLongOptionLabelsMatchTheirFullPattern(): void
    {
        $out = $this->t('C6 — 6 kg — Available Stock: 31.00 KG (6 cylinders)');

        $this->assertStringContainsString('دستیاب اسٹاک', $out);
        $this->assertStringContainsString('31.00 KG', $out);
        $this->assertStringEndsWith('سلنڈرز)', $out);
    }

    public function testShortSuffixPatternsDoNotEatTheEndOfAText(): void
    {
        // "{0} cylinders" must not match a text that continues after the word (keeps the ")").
        $this->assertSame('Stock (6 cylinders)', $this->t('Stock (6 cylinders)'));
    }

    public function testUnknownTextIsLeftExactlyAsItIs(): void
    {
        $this->assertSame('This sentence is not in the dictionary at all.', $this->t('This sentence is not in the dictionary at all.'));
        $this->assertSame('Rs. 1,250.00', $this->t('Rs. 1,250.00'));
    }

    public function testTextAlreadyInUrduIsNotTouched(): void
    {
        $this->assertSame('سیل ہسٹری', $this->t('سیل ہسٹری'));
    }

    public function testNativeDialogsAreTranslatedLineByLine(): void
    {
        $this->assertSame(["کسٹمر نہیں ملا۔\nفروخت کی ہسٹری"], self::$run['alerts']);
    }

    public function testTextThatMapsToItselfIsNeverRewritten(): void
    {
        // Rewriting with the same value still fires the page's MutationObserver, which loops forever.
        // Only the one real translation may write.
        $this->assertSame(1, self::$run['writes'], 'Only "Sale History" should be written.');
        $this->assertSame(['KG', 'Perfect LPG', 'Alpha — Beta', '  فروخت کی ہسٹری  ', 'Already اردو'], self::$run['nodes']);
    }

    public function testPageIsRevealedAfterTranslation(): void
    {
        $this->assertTrue(self::$run['revealed'], 'The i18n-pending class must be removed once the translator has run.');
    }
}
