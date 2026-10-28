<?php

use App\Controllers\Language;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;

/**
 * The language switch and the dictionary endpoint (no database needed).
 *
 * @internal
 */
final class LanguageControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    private function cookieValue(\CodeIgniter\Test\TestResponse $result): ?string
    {
        $cookie = $result->response()->getCookie('lpg_lang');

        return $cookie?->getValue();
    }

    public function testChoosingUrduStoresTheCookieAndRedirects(): void
    {
        $result = $this->controller(Language::class)->execute('set', 'ur');

        $this->assertSame('ur', $this->cookieValue($result));
        $this->assertTrue($result->isRedirect());
    }

    public function testChoosingEnglishStoresTheCookie(): void
    {
        $result = $this->controller(Language::class)->execute('set', 'en');

        $this->assertSame('en', $this->cookieValue($result));
    }

    public function testUnknownLanguageFallsBackToEnglish(): void
    {
        foreach (['fr', 'UR', '../etc', 'ur"><script>'] as $bad) {
            $result = $this->controller(Language::class)->execute('set', $bad);

            $this->assertSame('en', $this->cookieValue($result), "Value must be whitelisted: {$bad}");
        }
    }

    public function testCookieIsReadableByTheSignInScreenScript(): void
    {
        $cookie = $this->controller(Language::class)->execute('set', 'ur')->response()->getCookie('lpg_lang');

        $this->assertFalse($cookie->isHTTPOnly(), 'The sign-in screen also writes this cookie from JavaScript.');
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('Lax', $cookie->getSameSite());
        $this->assertGreaterThan(time() + 86400 * 300, $cookie->getExpiresTimestamp(), 'Remembered for about a year.');
    }

    public function testNeverRedirectsToAnotherSite(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.example.com/steal';
        $result = $this->controller(Language::class)->execute('set', 'ur');
        unset($_SERVER['HTTP_REFERER']);

        $this->assertStringNotContainsString('evil.example.com', $result->getRedirectUrl());
    }

    public function testDictionaryIsServedAsCacheableJavaScript(): void
    {
        $result   = $this->controller(Language::class)->execute('dictionary', 'ur');
        $response = $result->response();
        $body     = (string) $response->getBody();

        $this->assertStringStartsWith('window.LPG_I18N=', $body);
        $this->assertStringEndsWith(';', $body);
        $this->assertStringContainsString('application/javascript', $response->getHeaderLine('Content-Type'));
        $this->assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'), 'No conflicting no-store directive.');
        $this->assertNotSame('', $response->getHeaderLine('ETag'));

        $json = json_decode(substr($body, strlen('window.LPG_I18N='), -1), true);
        $this->assertSame('ur', $json['lang']);
        $this->assertArrayHasKey('Sale History', $json['exact']);
    }

    public function testEnglishDictionaryIsEmpty(): void
    {
        $body = (string) $this->controller(Language::class)->execute('dictionary', 'en')->response()->getBody();
        $json = json_decode(substr($body, strlen('window.LPG_I18N=') ,-1), true);

        $this->assertSame([], $json['exact']);
    }

    public function testUnchangedDictionaryAnswersNotModified(): void
    {
        $first = $this->controller(Language::class)->execute('dictionary', 'ur')->response();
        $etag  = $first->getHeaderLine('ETag');

        $request = service('incomingrequest', null, false);
        $request->setHeader('If-None-Match', $etag);
        $second = $this->withRequest($request)->controller(Language::class)->execute('dictionary', 'ur');

        $this->assertSame(304, $second->response()->getStatusCode());
        $this->assertSame('', (string) $second->response()->getBody(), 'Nothing is re-sent when the browser already has it.');
    }
}
