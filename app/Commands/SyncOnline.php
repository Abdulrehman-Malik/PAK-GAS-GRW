<?php

namespace App\Commands;

use App\Services\OnlineSyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Run every hour by the scheduled task the installer registers. Does nothing until the sync is turned on. */
class SyncOnline extends BaseCommand
{
    protected $group = 'LPG';

    protected $name = 'sync:online';

    protected $description = 'Copy this database to the configured online MySQL database.';

    public function run(array $params)
    {
        $service = new OnlineSyncService();
        if ((int) $service->settings()['enabled'] !== 1) {
            CLI::write('Online sync is turned off.');

            return EXIT_SUCCESS;
        }

        try {
            $result = $service->run('schedule');
            CLI::write('Copied ' . $result['tables'] . ' tables and ' . $result['rows'] . ' rows.');

            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('Online sync failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
