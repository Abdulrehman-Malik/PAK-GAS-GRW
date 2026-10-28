<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The welcome/sign-in screen must keep every piece of login behaviour:
 * the form fields, the migration gate, and graceful handling of missing shop data.
 *
 * @internal
 */
final class LoginViewTest extends CIUnitTestCase
{
    private function render(array $data = []): string
    {
        helper(['form', 'url']);

        return view('auth/login', array_merge([
            'title'         => 'Login',
            'companyName'   => 'Test LPG (Pvt.) LTD',
            'migrationGate' => ['ready' => true, 'blocked_message' => null, 'migrations' => [], 'total' => 0, 'completed' => 0],
            'shop'          => [],
        ], $data));
    }

    public function testReadySystemShowsLoginFormThatPostsToLogin(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('name="login"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="remember"', $html);
        $this->assertMatchesRegularExpression('#<form action="[^"]*/login" method="post"#', $html);
        $this->assertStringContainsString('Sign in', $html);
    }

    public function testLoginFormCarriesCsrfField(): void
    {
        $this->assertMatchesRegularExpression('#<input type="hidden" name="[^"]+" value="[^"]+"#', $this->render());
    }

    public function testPasswordToggleIsPresentAndAccessible(): void
    {
        $html = html_entity_decode($this->render(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('id="togglePassword"', $html);
        $this->assertStringContainsString('aria-label="Show password"', $html);
    }

    public function testReadySystemDoesNotShowMigrationRunner(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('migrations/run', $html);
        $this->assertStringContainsString('Database connected', $html);
    }

    public function testPendingMigrationsBlockLoginAndOfferRunner(): void
    {
        $html = $this->render(['migrationGate' => [
            'ready'           => false,
            'blocked_message' => 'Pending migrations must run first.',
            'migrations'      => [['seq' => 26, 'file' => '026_example.sql', 'status' => 'pending', 'message' => '']],
            'total'           => 26,
            'completed'       => 25,
        ]]);

        $this->assertStringContainsString('migrations/run', $html);
        $this->assertStringContainsString('Execute Pending Migrations', $html);
        $this->assertStringContainsString('026_example.sql', $html);
        $this->assertStringContainsString('25 of 26 migrations completed', $html);
        $this->assertStringNotContainsString('name="password"', $html, 'Login form must be hidden while migrations are pending.');
        $this->assertStringContainsString('Database update required', $html);
    }

    public function testShopDetailsAreShownAndEscaped(): void
    {
        $html = $this->render(['shop' => [
            'name'    => 'Model Town <b>Branch</b>',
            'address' => '14-B Main Boulevard',
            'city'    => 'Lahore',
            'phone'   => '0300 123 4567',
        ]]);

        $this->assertStringContainsString('14-B Main Boulevard, Lahore', $html);
        $this->assertStringContainsString('href="tel:03001234567"', $html);
        $this->assertStringNotContainsString('<b>Branch</b>', $html, 'Shop name must be HTML-escaped.');
        $this->assertStringContainsString('&lt;b&gt;Branch&lt;/b&gt;', $html);
    }

    public function testMissingShopDetailsFallBackWithoutErrors(): void
    {
        $html = $this->render(['shop' => []]);

        $this->assertStringContainsString('Perfect LPG', $html);
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('data-i18n="f_shop"', $html);
    }

    public function testServerRendersCurrentDateAndTimeAsFallback(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(date('l'), $html, 'Current weekday.');
        $this->assertStringContainsString(date('Y'), $html, 'Current year.');
        $this->assertStringContainsString('id="wlTime"', $html);
        $this->assertStringContainsString('visibilitychange', $html, 'Clock must catch up when the tab wakes.');
        $this->assertStringContainsString('renderClock', $html, 'Clock must keep updating in the browser.');
    }

    public function testPublicScreenNeverShowsFinancialOrStockFigures(): void
    {
        $html = strtolower($this->render());

        foreach (['sales today', 'cash session', 'gas available', 'receivable', 'rs. '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "Public sign-in screen must not expose: {$forbidden}");
        }
    }
}
