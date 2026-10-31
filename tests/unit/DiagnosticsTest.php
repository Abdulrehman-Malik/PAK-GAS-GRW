<?php

use App\Libraries\Diagnostics;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The diagnostics log may be sent to a developer, so it must not contain secrets.
 *
 * @internal
 */
final class DiagnosticsTest extends CIUnitTestCase
{
    public function testSecretKeysAreMaskedAtAnyDepth(): void
    {
        $masked = Diagnostics::mask(['login' => 'admin', 'password' => 'x', 'csrf_test_name' => 'y', 'nested' => ['smtp_password' => 'z', 'rate' => '10']]);

        $this->assertSame(['login' => 'admin', 'password' => '***', 'csrf_test_name' => '***', 'nested' => ['smtp_password' => '***', 'rate' => '10']], $masked);
    }

    public function testQueryWithSecretColumnLosesItsValues(): void
    {
        $sql = "UPDATE `shop_settings` SET `smtp_host` = 'smtp.gmail.com', `smtp_password` = 'it''s \\'secret' WHERE `id` = 1";

        $this->assertSame("UPDATE `shop_settings` SET `smtp_host` = '***', `smtp_password` = '***' WHERE `id` = 1", Diagnostics::maskSql($sql));
    }

    public function testOrdinaryQueryIsKept(): void
    {
        $sql = "SELECT * FROM `rate_cards` WHERE `price_list` = 'retail' AND `effective_from` <= '2026-10-31 10:00:00'";

        $this->assertSame($sql, Diagnostics::maskSql($sql));
    }

    public function testInstallerWritesBackupPathsWithForwardSlashes(): void
    {
        // CodeIgniter's .env reader keeps the quotes around a value with single backslashes.
        $configure = (string) file_get_contents(ROOTPATH . 'installer/scripts/configure.ps1');

        $this->assertStringContainsString('backup_dir       = (Fwd $BackupDir)', $configure);
        $this->assertStringContainsString('mysql            = (Fwd $MySql)', $configure);
        $this->assertStringContainsString("mysqldump        = (Fwd (Join-Path", $configure);
    }
}
