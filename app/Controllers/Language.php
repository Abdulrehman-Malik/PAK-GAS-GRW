<?php

namespace App\Controllers;

use App\Libraries\UiLanguage;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * App-wide language choice (English / Urdu). Public on purpose: the dictionary is
 * static interface text, and the switch only stores a cookie.
 */
class Language extends Controller
{
    /** GET language/{code}: remember the choice, then return to the page the user was on. */
    public function set(string $lang = ''): RedirectResponse
    {
        $lang = UiLanguage::resolve($lang);

        return redirect()->to($this->returnUrl())->setCookie(
            UiLanguage::COOKIE,
            $lang,
            31536000,
            '',
            '/',
            '',
            false,
            false, // not HttpOnly: the sign-in screen also switches language from JavaScript
            'Lax'
        );
    }

    /** GET i18n/{code}.js: the dictionary for the browser translator. Cached for a year per version. */
    public function dictionary(string $lang = ''): ResponseInterface
    {
        $lang = UiLanguage::resolve($lang);
        $json = json_encode(
            UiLanguage::appPayload($lang),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $body = 'window.LPG_I18N=' . $json . ';';
        $etag = '"' . md5($body) . '"';

        // CodeIgniter sends "no-store" by default; drop it, or the header would contradict the cache rule.
        $response = $this->response
            ->setHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setHeader('ETag', $etag);

        if ($this->request->getHeaderLine('If-None-Match') === $etag) {
            return $response->setStatusCode(304)->setBody('');
        }

        return $response->setBody($body);
    }

    /** Back to the page the user came from, but only if it is on this site (never an external URL). */
    private function returnUrl(): string
    {
        $referer = (string) $this->request->getServer('HTTP_REFERER');
        $host    = parse_url($referer, PHP_URL_HOST);
        $ours    = [$this->request->getUri()->getHost(), parse_url(base_url('/'), PHP_URL_HOST)];

        return is_string($host) && in_array($host, $ours, true) ? $referer : site_url('/');
    }
}
