<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards for rules in CLAUDE.md and for the landing/login merge, so a push
 * cannot silently reintroduce something that was deliberately removed.
 *
 * @internal
 */
final class ProjectRulesTest extends CIUnitTestCase
{
    private function appFiles(string $extension): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function testRootAndLoginRoutesAreRegistered(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');

        $this->assertStringContainsString("get('/','Landing::index')", $routes);
        $this->assertStringContainsString("get('login','Auth::showLogin')", $routes);
        $this->assertStringContainsString("post('login','Auth::attemptLogin')", $routes);
        $this->assertStringContainsString('migrations/run', $routes, 'Migration route must stay registered.');
    }

    public function testLandingDelegatesToLoginSoBothScreensBehaveTheSame(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Landing.php');

        $this->assertStringContainsString('(new Auth())->showLogin()', $source);
        $this->assertFileDoesNotExist(APPPATH . 'Views/landing/index.php', 'Old marketing landing view must stay removed.');
    }

    public function testSignedInUsersAreStillRedirectedToDashboard(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Auth.php');

        $this->assertStringContainsString("if(session()->get('isLoggedIn')) return redirect()->to(site_url('dashboard'));", $source);
        $this->assertStringContainsString('shouldRedirectToSetup', $source);
    }

    public function testPublicShopInfoOnlyReadsNonSensitiveColumns(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Auth.php');

        $this->assertStringContainsString("->select('name,address,city,phone')", $source);
        $this->assertStringContainsString("table('locations')", $source);
    }

    public function testNoTunnelOrEnvironmentSpecificUrlsInApplicationCode(): void
    {
        $patterns = ['trycloudflare.com', 'cloudflared', 'ngrok', 'loca.lt'];

        foreach (array_merge($this->appFiles('php'), $this->appFiles('js')) as $file) {
            $content = strtolower((string) file_get_contents($file));
            foreach ($patterns as $pattern) {
                $this->assertStringNotContainsString($pattern, $content, "CLAUDE.md forbids tunnel-specific logic ({$pattern}) in {$file}");
            }
        }
    }

    public function testWelcomeStylesShipWithTheTheme(): void
    {
        $css = file_get_contents(ROOTPATH . 'public/css/lpg-theme.css');

        $this->assertStringContainsString('.wl-card', $css, 'Welcome screen styles must ship with the theme.');
    }
}
