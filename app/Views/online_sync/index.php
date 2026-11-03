<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$value = static fn(string $key) => old($key) ?? ($settings[$key] ?? '');
$formEnabled = old('host') !== null ? !empty(old('enabled')) : (int)$settings['enabled'] === 1;
$formTls = old('host') !== null ? !empty(old('use_tls')) : (int)$settings['use_tls'] === 1;
$last = $logs[0] ?? null;
?>
<div class="mb-3">
    <h4 class="mb-1">Online Database Sync</h4>
    <p class="text-muted small mb-0">Copies this computer's database to one online MySQL database every hour. This computer is the master: anything changed online is overwritten at the next sync.</p>
</div>

<div class="alert alert-warning py-2 small">
    Everything is copied online, including customer data, user password hashes and the email password. Use a private database with a strong password.
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Connection</div>
    <div class="card-body">
        <form method="post" action="<?= site_url('online-sync/save') ?>" id="syncForm">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label mb-1" for="syncHost">URL or Host</label>
                    <input type="text" class="form-control" id="syncHost" name="host" value="<?= esc($value('host'), 'attr') ?>" placeholder="db.example.com or mysql://db.example.com:3306/shop" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1" for="syncPort">Port</label>
                    <input type="number" class="form-control" id="syncPort" name="port" min="1" max="65535" value="<?= esc($value('port'), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1" for="syncDatabase">Database Name</label>
                    <input type="text" class="form-control" id="syncDatabase" name="database_name" maxlength="64" value="<?= esc($value('database_name'), 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label mb-1" for="syncUser">User Name</label>
                    <input type="text" class="form-control" id="syncUser" name="username" maxlength="128" autocomplete="off" value="<?= esc($value('username'), 'attr') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label mb-1" for="syncPassword">Password</label>
                    <input type="password" class="form-control" id="syncPassword" name="password" autocomplete="new-password" placeholder="<?= !empty($settings['password_encrypted']) ? 'Leave blank to keep the saved password' : 'Enter the password' ?>">
                </div>
                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="syncEnabled" name="enabled" value="1" <?= $formEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="syncEnabled">Sync every hour</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="syncTls" name="use_tls" value="1" <?= $formTls ? 'checked' : '' ?>>
                        <label class="form-check-label" for="syncTls">Encrypt the connection (TLS)</label>
                    </div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="submit" class="btn btn-outline-primary" formaction="<?= site_url('online-sync/test') ?>">Test Connection</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Sync Now</div>
    <div class="card-body">
        <form method="post" action="<?= site_url('online-sync/run') ?>" class="mb-3">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" <?= empty($settings['password_encrypted']) ? 'disabled' : '' ?>>Sync Now</button>
            <span class="small text-muted ms-2">Uses the saved connection.</span>
        </form>
        <form method="post" action="<?= site_url('online-sync/run') ?>" class="border-top pt-3" onsubmit="return confirm('Delete everything in the online database and replace it with this computer\'s data?');">
            <?= csrf_field() ?>
            <label class="form-label small mb-1" for="syncReplace">Only if the online database has other data or belongs to another computer: type <code>REPLACE ONLINE DATABASE</code> to delete all of it and sync.</label>
            <div class="input-group" style="max-width:520px">
                <input type="text" class="form-control" id="syncReplace" name="replace_confirm" autocomplete="off" required>
                <button type="submit" class="btn btn-outline-danger" <?= empty($settings['password_encrypted']) ? 'disabled' : '' ?>>Replace and Sync</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Sync History</div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0" id="syncHistory">
            <thead><tr><th>Started</th><th>Finished</th><th>Run By</th><th>Status</th><th class="text-end">Tables</th><th class="text-end">Rows</th><th class="text-end">Migrations</th><th>Message</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?><tr><td colspan="8" class="text-muted text-center py-3">No syncs yet.</td></tr><?php endif; ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="text-nowrap"><?= esc(date('d-M-Y h:i A', strtotime($log['started_at']))) ?></td>
                    <td class="text-nowrap"><?= $log['finished_at'] ? esc(date('h:i:s A', strtotime($log['finished_at']))) : '' ?></td>
                    <td><?= $log['trigger_type'] === 'schedule' ? 'Schedule' : 'Manual' ?></td>
                    <td>
                        <?php if ($log['status'] === 'success'): ?><span class="badge text-bg-success">Success</span>
                        <?php elseif ($log['status'] === 'failed'): ?><span class="badge text-bg-danger">Failed</span>
                        <?php else: ?><span class="badge text-bg-secondary">Running</span><?php endif; ?>
                    </td>
                    <td class="text-end"><?= (int)$log['tables_copied'] ?></td>
                    <td class="text-end"><?= (int)$log['rows_copied'] ?></td>
                    <td class="text-end"><?= (int)$log['migrations_run'] ?></td>
                    <td class="small"><?= esc($log['message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
