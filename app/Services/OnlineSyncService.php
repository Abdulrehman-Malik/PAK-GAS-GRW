<?php

namespace App\Services;

use Config\Database;
use RuntimeException;

/**
 * One-way copy of this computer's database to one online MySQL database, for one PC per online database.
 *
 * Each run: connects, refuses a database that is not this PC's copy, brings the online schema to the
 * migration level of this install, checks that the schemas match, then replaces the online rows
 * inside one transaction, so the online copy never shows a half-finished sync.
 * The online data is overwritten every time: anything changed online is lost.
 */
class OnlineSyncService
{
    /** Rows that stay on this computer. The tables themselves still exist online, so the schemas match. */
    public const LOCAL_ONLY_TABLES = ['online_sync_settings', 'online_sync_log', 'lpg_migration_history'];
    /** Online table recording which PC owns the online database. */
    public const MARKER_TABLE = 'lpg_sync_target';
    public const LOG_KEEP = 500;
    private const LOCK = 'lpg_online_sync';
    private const BATCH_BYTES = 1048576;

    protected $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public function settings(): array
    {
        $row = $this->db->table('online_sync_settings')->where('id', 1)->get()->getRowArray();
        return $row ?: ['id' => 1, 'enabled' => 0, 'host' => '', 'port' => 3306, 'database_name' => '', 'username' => '', 'password_encrypted' => null, 'use_tls' => 1, 'instance_id' => ''];
    }

    public function logs(int $limit = 50): array
    {
        return $this->db->table('online_sync_log')->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /**
     * Accepts a host name or a URL such as mysql://db.example.com:3306/shop.
     *
     * @return array{host:string,port:?int,database:?string}
     */
    public static function parseTarget(string $target): array
    {
        $target = trim($target);
        if ($target === '') throw new RuntimeException('Enter the online database URL or host.');
        $parts = parse_url(str_contains($target, '://') ? $target : 'mysql://' . $target);
        $host = (string)($parts['host'] ?? '');
        if ($parts === false || !preg_match('/^[A-Za-z0-9._:\-\[\]]+$/', $host)) throw new RuntimeException('Enter a valid online database URL or host.');
        $database = trim((string)($parts['path'] ?? ''), '/');
        return ['host' => $host, 'port' => isset($parts['port']) ? (int)$parts['port'] : null, 'database' => $database !== '' ? $database : null];
    }

    /** Settings from the form. A blank password keeps the saved one. */
    public function fromForm(array $post): array
    {
        $target = self::parseTarget((string)($post['host'] ?? ''));
        $port = trim((string)($post['port'] ?? ''));
        $port = $port !== '' ? (int)$port : ($target['port'] ?? 3306);
        if ($port < 1 || $port > 65535) throw new RuntimeException('Enter a port between 1 and 65535.');
        $database = trim((string)($post['database_name'] ?? '')) ?: (string)$target['database'];
        if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $database)) throw new RuntimeException('Enter the online database name.');
        $username = trim((string)($post['username'] ?? ''));
        if ($username === '') throw new RuntimeException('Enter the online database user name.');

        $saved = $this->settings();
        $password = (string)($post['password'] ?? '');
        return [
            'enabled' => !empty($post['enabled']) ? 1 : 0, 'host' => $target['host'], 'port' => $port, 'database_name' => $database, 'username' => $username,
            'password_encrypted' => $password !== '' ? base64_encode(service('encrypter')->encrypt($password)) : ($saved['password_encrypted'] ?? null),
            'use_tls' => !empty($post['use_tls']) ? 1 : 0,
        ];
    }

    public function save(array $post, int $userId): void
    {
        $data = $this->fromForm($post);
        if (empty($data['password_encrypted'])) throw new RuntimeException('Enter the online database password.');
        $saved = $this->settings();
        $data += ['instance_id' => $saved['instance_id'] ?: bin2hex(random_bytes(16)), 'updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')];
        if ($this->db->table('online_sync_settings')->where('id', 1)->countAllResults() > 0) {
            $this->db->table('online_sync_settings')->where('id', 1)->update($data);
        } else {
            $this->db->table('online_sync_settings')->insert($data + ['id' => 1]);
        }
        AuditService::log('UPDATE', 'online_sync_settings', 1, null, ['enabled' => $data['enabled'], 'host' => $data['host'], 'port' => $data['port'], 'database_name' => $data['database_name'], 'username' => $data['username'], 'use_tls' => $data['use_tls']], $userId, (int)session()->get('location_id') ?: null);
    }

    /** Connects and reports what is online, without changing anything. */
    public function test(array $settings): string
    {
        $online = $this->connect($settings);
        try {
            $this->refuseLocalDatabase($online);
            $version = (string)$online->query('SELECT VERSION()')->fetch_row()[0];
            $tables = $this->tableNames($online);
            $state = 'The online database is empty and is ready for the first sync.';
            if ($tables) {
                $owner = $this->ownerOf($online, $tables);
                if ($owner === null) $state = 'The online database already has ' . count($tables) . ' table(s) that were not created by this sync. They can only be used with Replace.';
                elseif ($owner !== (string)($settings['instance_id'] ?? $this->settings()['instance_id'])) $state = 'The online database belongs to another computer. It can only be used with Replace.';
                else $state = 'The online database is the copy of this computer.';
            }
            return 'Connected to MySQL ' . $version . '. ' . $state;
        } finally {
            $online->close();
        }
    }

    /**
     * Runs one sync. Throws when it fails, after recording the failure in the history.
     *
     * @return array{tables:int,rows:int,migrations:int}
     */
    public function run(string $trigger, ?int $userId = null, bool $replace = false): array
    {
        $locked = (int)($this->db->query('SELECT GET_LOCK(?,0) AS locked', [self::LOCK])->getRowArray()['locked'] ?? 0);
        if ($locked !== 1) throw new RuntimeException('Another sync is already running.');
        $this->db->table('online_sync_log')->insert(['started_at' => date('Y-m-d H:i:s'), 'status' => 'running', 'trigger_type' => $trigger, 'created_by' => $userId]);
        $logId = (int)$this->db->insertID();
        try {
            $settings = $this->settings();
            if ($settings['host'] === '' || empty($settings['password_encrypted'])) throw new RuntimeException('Save the online database connection first.');
            $online = $this->connect($settings);
            try {
                $this->refuseLocalDatabase($online);
                $this->prepareTarget($online, (string)$settings['instance_id'], $replace);
                $migrations = $this->applyMigrations($online);
                $this->alignSchema($online);
                [$tables, $rows] = $this->copyData($online);
                $online->query('UPDATE `' . self::MARKER_TABLE . '` SET last_sync_at=NOW()');
            } finally {
                $online->close();
            }
            $message = 'Copied ' . $tables . ' tables and ' . $rows . ' rows.';
            $this->db->table('online_sync_log')->where('id', $logId)->update(['finished_at' => date('Y-m-d H:i:s'), 'status' => 'success', 'tables_copied' => $tables, 'rows_copied' => $rows, 'migrations_run' => $migrations, 'message' => $message]);
            return ['tables' => $tables, 'rows' => $rows, 'migrations' => $migrations];
        } catch (\Throwable $e) {
            log_message('error', 'Online sync failed: {message}', ['message' => $e->getMessage()]);
            $this->db->table('online_sync_log')->where('id', $logId)->update(['finished_at' => date('Y-m-d H:i:s'), 'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        } finally {
            $this->db->query('DELETE FROM online_sync_log WHERE id < ?', [max(0, $logId - self::LOG_KEEP)]);
            $this->db->query('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    private function connect(array $settings): \mysqli
    {
        if (empty($settings['password_encrypted'])) throw new RuntimeException('Enter the online database password.');
        try {
            $password = service('encrypter')->decrypt(base64_decode((string)$settings['password_encrypted'], true));
        } catch (\Throwable) {
            throw new RuntimeException('The saved online database password cannot be read on this computer. Enter it again.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $online = mysqli_init();
            $online->options(MYSQLI_OPT_CONNECT_TIMEOUT, 20);
            $flags = 0;
            if ((int)$settings['use_tls'] === 1) {
                $online->ssl_set(null, null, null, null, null);
                $flags = MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
            }
            $online->real_connect((string)$settings['host'], (string)$settings['username'], (string)$password, (string)$settings['database_name'], (int)$settings['port'], null, $flags);
            $online->set_charset('utf8mb4');
            // Same stored values whatever the online server's own defaults are.
            $online->query("SET SESSION time_zone='+00:00', sql_mode='NO_AUTO_VALUE_ON_ZERO'");
            return $online;
        } catch (\mysqli_sql_exception $e) {
            throw new RuntimeException('Could not connect to the online database: ' . $e->getMessage());
        } finally {
            unset($password);
        }
    }

    /** The whole online database is replaced, so it must never be this computer's own. */
    private function refuseLocalDatabase(\mysqli $online): void
    {
        $there = $online->query('SELECT @@server_uuid,DATABASE()')->fetch_row();
        $here = $this->db->query('SELECT @@server_uuid AS uuid,DATABASE() AS name')->getRowArray();
        if ($there[0] === $here['uuid'] && strcasecmp((string)$there[1], (string)$here['name']) === 0) {
            throw new RuntimeException('The online database is this computer\'s own database. Choose a different database.');
        }
    }

    private function tableNames(\mysqli $online): array
    {
        $names = [];
        $result = $online->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()");
        while ($row = $result->fetch_row()) $names[] = (string)$row[0];
        return $names;
    }

    /** The PC that owns the online database, or null when no sync created it. */
    private function ownerOf(\mysqli $online, array $tables): ?string
    {
        if (!in_array(self::MARKER_TABLE, array_map('strtolower', $tables), true)) return null;
        $row = $online->query('SELECT instance_id FROM `' . self::MARKER_TABLE . '` LIMIT 1')->fetch_row();
        return $row ? (string)$row[0] : null;
    }

    private function prepareTarget(\mysqli $online, string $instanceId, bool $replace): void
    {
        $tables = $this->tableNames($online);
        if ($tables) {
            $owner = $this->ownerOf($online, $tables);
            if (!$replace && $owner === null) throw new RuntimeException('The online database already has tables that this sync did not create. Nothing was changed. Use Replace only if its contents may be deleted.');
            if (!$replace && $owner !== $instanceId) throw new RuntimeException('The online database belongs to another computer. Nothing was changed. Use Replace only if this computer should take it over.');
            if ($replace) {
                $this->dropEverything($online);
                $tables = [];
            }
        }
        if (!$tables) {
            $online->query('CREATE TABLE `' . self::MARKER_TABLE . '`(instance_id CHAR(32) NOT NULL PRIMARY KEY,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_sync_at DATETIME NULL)ENGINE=InnoDB');
            $stmt = $online->prepare('INSERT INTO `' . self::MARKER_TABLE . '`(instance_id) VALUES (?)');
            $stmt->bind_param('s', $instanceId);
            $stmt->execute();
        }
        if ($this->tableNames($online) === [self::MARKER_TABLE]) $this->installSchema($online);
    }

    private function dropEverything(\mysqli $online): void
    {
        $online->query('SET FOREIGN_KEY_CHECKS=0');
        $result = $online->query('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
        foreach ($result->fetch_all() as [$name, $type]) {
            $online->query(($type === 'VIEW' ? 'DROP VIEW `' : 'DROP TABLE `') . str_replace('`', '``', (string)$name) . '`');
        }
        $online->query('SET FOREIGN_KEY_CHECKS=1');
    }

    private function installSchema(\mysqli $online): void
    {
        $sql = (string)file_get_contents(ROOTPATH . 'database' . DIRECTORY_SEPARATOR . 'schema.sql');
        if (trim($sql) === '') throw new RuntimeException('database/schema.sql is missing or empty.');
        $online->multi_query($sql);
        do {
            if ($result = $online->store_result()) $result->free();
        } while ($online->more_results() && $online->next_result());
    }

    /** Runs on the online database the migrations it has not had yet, using the normal runner. */
    private function applyMigrations(\mysqli $online): int
    {
        $known = array_map('basename', glob(ROOTPATH . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.sql') ?: []);
        if (in_array('lpg_migration_history', array_map('strtolower', $this->tableNames($online)), true)) {
            $result = $online->query("SELECT DISTINCT migration_file FROM lpg_migration_history WHERE status IN ('success','skipped')");
            while ($row = $result->fetch_row()) {
                if (!in_array((string)$row[0], $known, true)) throw new RuntimeException('The online database is on a newer version than this computer (' . $row[0] . '). Upgrade this computer first.');
            }
        }
        $connection = Database::connect(['DBDriver' => 'MySQLi', 'hostname' => '', 'username' => '', 'password' => '', 'database' => '', 'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci'], false);
        $connection->connID = $online;
        $result = (new MigrationRunnerService($connection))->runPending();
        return count(array_filter($result['executed'] ?? [], static fn($row) => ($row['status'] ?? '') === 'success'));
    }

    /** table => column => "type nullable". Integer display widths differ between MySQL versions, so they are ignored. */
    private static function normalizeColumns(iterable $rows): array
    {
        $schema = [];
        foreach ($rows as $row) {
            $type = (string)preg_replace('/\b((?:tiny|small|medium|big)?int)\(\d+\)/i', '$1', strtolower((string)$row[2]));
            $schema[strtolower((string)$row[0])][strtolower((string)$row[1])] = $type . ' ' . $row[3];
        }
        return $schema;
    }

    /** The differences that stop a sync: tables are not listed here when they can simply be created. */
    public static function schemaDifferences(array $local, array $online): array
    {
        $differences = [];
        foreach ($local as $table => $columns) {
            if (!isset($online[$table])) continue;
            foreach ($columns as $column => $definition) {
                if (!isset($online[$table][$column])) $differences[] = $table . '.' . $column . ' is missing online';
                elseif ($online[$table][$column] !== $definition) $differences[] = $table . '.' . $column . ' is ' . $online[$table][$column] . ' online but ' . $definition . ' here';
            }
            foreach (array_keys($online[$table]) as $column) {
                if (!isset($columns[$column])) $differences[] = $table . '.' . $column . ' exists only online';
            }
        }
        return $differences;
    }

    private function alignSchema(\mysqli $online): void
    {
        $sql = 'SELECT c.TABLE_NAME,c.COLUMN_NAME,c.COLUMN_TYPE,c.IS_NULLABLE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE=\'BASE TABLE\'';
        $local = self::normalizeColumns(array_map('array_values', $this->db->query($sql)->getResultArray()));
        $there = self::normalizeColumns($online->query($sql)->fetch_all());
        unset($there[self::MARKER_TABLE]);

        $online->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_keys($local) as $table) {
            if (isset($there[$table])) continue;
            $create = $this->db->query('SHOW CREATE TABLE `' . $table . '`')->getRowArray();
            $online->query((string)$create['Create Table']);
            $there[$table] = $local[$table];
        }
        $online->query('SET FOREIGN_KEY_CHECKS=1');

        $differences = self::schemaDifferences($local, $there);
        if ($differences) throw new RuntimeException('The online schema does not match this computer, so no data was copied: ' . implode('; ', array_slice($differences, 0, 5)) . (count($differences) > 5 ? '; and ' . (count($differences) - 5) . ' more' : '') . '.');
    }

    /** @return array{0:int,1:int} tables and rows copied */
    private function copyData(\mysqli $online): array
    {
        $tables = array_column($this->db->query("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->getResultArray(), 'name');
        $tables = array_values(array_filter($tables, static fn($t) => !in_array(strtolower($t), self::LOCAL_ONLY_TABLES, true)));
        $limit = min(self::BATCH_BYTES, max(65536, (int)$online->query('SELECT @@max_allowed_packet')->fetch_row()[0] - 4096));
        $source = $this->db->connID;
        $this->db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $online->query('SET FOREIGN_KEY_CHECKS=0');
        $online->begin_transaction();
        $total = 0;
        try {
            foreach ($tables as $table) {
                $columns = [];
                foreach ($this->db->query("SELECT COLUMN_NAME AS name,EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION", [$table])->getResultArray() as $column) {
                    // Computed columns cannot be written. MySQL also labels a CURRENT_TIMESTAMP default DEFAULT_GENERATED, and those must be copied.
                    if (!preg_match('/\b(VIRTUAL|STORED) GENERATED\b/i', (string)$column['extra'])) $columns[] = '`' . $column['name'] . '`';
                }
                $list = implode(',', $columns);
                $online->query('DELETE FROM `' . $table . '`');
                $copied = 0;
                $head = 'INSERT INTO `' . $table . '` (' . $list . ') VALUES ';
                $batch = '';
                $rows = $source->query('SELECT ' . $list . ' FROM `' . $table . '`', MYSQLI_USE_RESULT);
                while ($row = $rows->fetch_row()) {
                    $values = [];
                    foreach ($row as $value) $values[] = $value === null ? 'NULL' : "'" . $online->real_escape_string((string)$value) . "'";
                    $tuple = '(' . implode(',', $values) . ')';
                    if ($batch !== '' && strlen($batch) + strlen($tuple) > $limit) {
                        $online->query($head . $batch);
                        $batch = '';
                    }
                    $batch .= ($batch === '' ? '' : ',') . $tuple;
                    $copied++;
                }
                $rows->free();
                if ($batch !== '') $online->query($head . $batch);
                $count = (int)$online->query('SELECT COUNT(*) FROM `' . $table . '`')->fetch_row()[0];
                if ($count !== $copied) throw new RuntimeException('Table ' . $table . ' copied ' . $copied . ' rows but has ' . $count . ' online.');
                $total += $copied;
            }
            $online->commit();
            $this->db->query('COMMIT');
            $online->query('SET FOREIGN_KEY_CHECKS=1');
            return [count($tables), $total];
        } catch (\Throwable $e) {
            try {
                $online->rollback();
            } catch (\Throwable) {
                // The closed connection rolls back on its own.
            }
            $this->db->query('ROLLBACK');
            throw new RuntimeException('The data copy failed and the online data was left as it was: ' . $e->getMessage());
        }
    }
}
