<?php

use App\Controllers\DatabaseBackup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Restoring replaces the whole database, users and permissions included, so only an administrator
 * may do it (the role is checked before anything touches the database).
 *
 * @internal
 */
final class DatabaseBackupAccessTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    /** @return iterable<string, array{0: string|null}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'manager' => ['MANAGER'];
        yield 'cashier' => ['CASHIER'];
        yield 'not signed in' => [null];
    }

    #[DataProvider('nonAdminRoles')]
    public function testOnlyAnAdministratorCanRestore(?string $role): void
    {
        session()->remove('role');
        if ($role !== null) {
            session()->set('role', $role);
        }

        $result = $this->withUri(site_url('database-backup/restore'))
            ->controller(DatabaseBackup::class)
            ->execute('restore');

        $this->assertTrue($result->isRedirect());
        $this->assertSame('Only an administrator can restore a database backup.', session()->getFlashdata('error'));
    }

    public function testRefreshFailureWarnsOnceTablesMayHaveBeenCleared(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/DatabaseBackup.php');
        $flag = strpos($source, '$refreshAttempted = true;');
        $refresh = strpos($source, 'TransactionalDataRefreshService())->refresh()');

        $this->assertNotFalse($flag, 'The refresh must mark that it started, or the partial-refresh warning can never be shown.');
        $this->assertNotFalse($refresh);
        $this->assertLessThan($refresh, $flag, '$refreshAttempted must be set before the refresh runs.');
        $this->assertStringContainsString('The database may be partially refreshed', $source);
    }
}
