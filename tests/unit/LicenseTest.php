<?php

use CodeIgniter\Test\CIUnitTestCase;

require_once ROOTPATH . 'installer/scripts/license-check.php';

/**
 * The installer's machine-locked license check (installer/scripts/license-check.php). Keys are made
 * here the way installer/license/keygen.ps1 makes them: base64url JSON payload, a dot, and the
 * base64url RSA-SHA256 signature of the payload text.
 *
 * @internal
 */
final class LicenseTest extends CIUnitTestCase
{
    private const MACHINE = 'B8AC-QMJ3-84GM-V3AK-PKG3';

    private static string $privateKey;
    private static string $publicKey;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        self::$privateKey = $privateKey;
        self::$publicKey = openssl_pkey_get_details($key)['key'];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function issue(string $machine, string $expires = ''): string
    {
        $body = self::base64Url(json_encode(['v' => 1, 'm' => $machine, 'c' => 'Test Shop', 'i' => '2026-10-27', 'e' => $expires]));
        openssl_sign($body, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

        return $body . '.' . self::base64Url($signature);
    }

    public function testKeyForThisMachineIsAccepted(): void
    {
        $this->assertSame('Test Shop', installer_license_verify("  {$this->issue(self::MACHINE)}\r\n", self::$publicKey, self::MACHINE)['c']);
    }

    public function testKeyForAnotherMachineIsRejected(): void
    {
        $this->expectExceptionMessage('another computer');
        installer_license_verify($this->issue('AAAA-BBBB-CCCC-DDDD-EEEE'), self::$publicKey, self::MACHINE);
    }

    public function testKeyEditedForThisMachineIsRejected(): void
    {
        $signature = explode('.', $this->issue('AAAA-BBBB-CCCC-DDDD-EEEE'))[1];
        $forged = self::base64Url(json_encode(['v' => 1, 'm' => self::MACHINE, 'c' => 'Test Shop']));

        $this->expectExceptionMessage('not valid');
        installer_license_verify($forged . '.' . $signature, self::$publicKey, self::MACHINE);
    }

    public function testKeyIsValidUntilTheEndOfItsExpiryDay(): void
    {
        $key = $this->issue(self::MACHINE, '2026-12-31');

        $this->assertSame(self::MACHINE, installer_license_verify($key, self::$publicKey, self::MACHINE, '2026-12-31')['m']);
        $this->expectExceptionMessage('expired on 2026-12-31');
        installer_license_verify($key, self::$publicKey, self::MACHINE, '2027-01-01');
    }
}
