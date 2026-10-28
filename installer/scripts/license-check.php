<?php
// Machine-locked license check, so copying an installed folder to another computer does not carry a
// working license with it. It runs twice over, so removing one hook is not enough:
//  - before every web request, through php.ini's auto_prepend_file (configure.ps1);
//  - from the app's entry files (app.ini [license] entry_points), into which build.ps1 injects a
//    require_once of this file in the shipped copy only. Deleting this file breaks the app.
// build.ps1 also embeds the public key below, so it cannot be swapped for another key pair's.
//
// Installed layout: <InstallDir>\installer\ holds this file and machine-id.ps1; setup.exe saves the
// client's key in <InstallDir>\config\license.key after checking it (license.ps1).
// A key is "<payload>.<signature>" (base64url): JSON {v, m (Machine ID), c (customer), i (issued),
// e (expires, optional)} signed RSA-SHA256 by license\keygen.ps1. Command-line PHP is not checked.

if (!function_exists('installer_license_verify')) {
    /** The app's public key, embedded by build.ps1 in place of the marker. */
    function installer_license_public_key(): string
    {
        // A PEM holds only base64, dashes and newlines, so it is safe inside single quotes.
        return '{{PUBLIC_KEY_PEM}}';
    }

    /**
     * Checks a key's signature, Machine ID and expiry (Y-m-d); returns its payload or throws with the reason.
     *
     * @return array<string, mixed>
     */
    function installer_license_verify(string $key, string $publicKeyPem, string $machineId, ?string $today = null): array
    {
        $b64 = static fn (string $text): string => (string) base64_decode(strtr($text, '-_', '+/'), true);
        $parts = explode('.', (string) preg_replace('/\s+/', '', $key));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException('The installed license key is not a valid key.');
        }
        $signature = $b64($parts[1]);
        if (openssl_pkey_get_public($publicKeyPem) === false) {
            throw new RuntimeException('The license public key of this installation is missing or damaged.');
        }
        if ($signature === '' || openssl_verify($parts[0], $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('The installed license key is not valid.');
        }
        $payload = json_decode($b64($parts[0]), true);
        if (!is_array($payload) || (string) ($payload['m'] ?? '') === '') {
            throw new RuntimeException('The installed license key is not valid.');
        }
        if ($payload['m'] !== $machineId) {
            throw new RuntimeException('This license is for another computer (' . $payload['m'] . ').');
        }
        $expires = (string) ($payload['e'] ?? '');
        if ($expires !== '' && ($today ?? date('Y-m-d')) > $expires) {
            throw new RuntimeException('This license expired on ' . $expires . '.');
        }

        return $payload;
    }

    /**
     * Reading the hardware takes a few seconds, so the ID is cached for a day, tied to the computer
     * name: a cache copied along with the folder is not trusted on another PC.
     */
    function installer_license_machine_id(string $installDir): string
    {
        $pattern = '/^[0-9A-Z]{4}(-[0-9A-Z]{4}){4}$/';
        $cache = $installDir . '\\config\\license-machine-id.cache';
        $computer = strtoupper((string) getenv('COMPUTERNAME'));
        if (is_file($cache) && filemtime($cache) > time() - 86400) {
            [$cachedComputer, $cachedId] = array_pad(explode('|', trim((string) file_get_contents($cache)), 2), 2, '');
            if ($cachedComputer === $computer && preg_match($pattern, $cachedId)) {
                return $cachedId;
            }
        }
        $script = $installDir . '\\installer\\machine-id.ps1';
        $id = trim((string) shell_exec('powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . $script . '" 2>NUL'));
        if (!preg_match($pattern, $id)) {
            throw new RuntimeException('The Machine ID of this computer could not be read.');
        }
        @file_put_contents($cache, $computer . '|' . $id);

        return $id;
    }

    function installer_license_check(string $installDir): void
    {
        $machineId = '';
        try {
            $machineId = installer_license_machine_id($installDir);
            $keyFile = $installDir . '\\config\\license.key';
            if (!is_file($keyFile)) {
                throw new RuntimeException('No license is installed on this computer.');
            }
            installer_license_verify((string) file_get_contents($keyFile), installer_license_public_key(), $machineId);

            return;
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }

        $install = @parse_ini_file($installDir . '\\config\\install.ini', false, INI_SCANNER_RAW) ?: [];
        $name = htmlspecialchars((string) ($install['name'] ?? 'This application'), ENT_QUOTES);
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>License required</title><style>body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#f4f6f8;color:#1f2933}',
            '.box{max-width:520px;margin:12vh auto;background:#fff;border-radius:8px;padding:32px;box-shadow:0 2px 12px rgba(0,0,0,.08)}',
            'h1{margin:0 0 12px;font-size:22px;color:#b42318}p{line-height:1.5}code{padding:6px 10px;background:#f0f2f5;border-radius:4px;font-size:18px;letter-spacing:1px;user-select:all}</style></head>',
            '<body><div class="box"><h1>', $name, ' is not licensed for this computer</h1><p>', htmlspecialchars($message, ENT_QUOTES), '</p>';
        if ($machineId !== '') {
            echo '<p>Send this Machine ID to your supplier to get a license key, then run setup again and enter the key:</p><p><code>',
                htmlspecialchars($machineId, ENT_QUOTES), '</code></p>';
        }
        echo '</div></body></html>';
        exit;
    }
}

// Once per request: the second hook finds the first one already passed.
if (PHP_SAPI !== 'cli' && !defined('INSTALLER_LICENSE_CHECKED')) {
    installer_license_check(dirname(__DIR__));
    define('INSTALLER_LICENSE_CHECKED', true);
}
