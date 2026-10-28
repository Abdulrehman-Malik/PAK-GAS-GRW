<?php
use App\Libraries\UiLanguage as Ui;

$lang = Ui::resolve($lang ?? null);
$meta = Ui::meta($lang);
$now  = Ui::now($lang);

$companyName = trim((string)($companyName ?? env('app.companyName', 'Perfect LPG (Pvt.) LTD'))) ?: 'Perfect LPG (Pvt.) LTD';

$shop      = $shop ?? [];
$shopName  = trim((string)($shop['name'] ?? '')) ?: 'Perfect LPG';
$shopPlace = trim(implode(', ', array_filter([trim((string)($shop['address'] ?? '')), trim((string)($shop['city'] ?? ''))])));
$shopPhone = trim((string)($shop['phone'] ?? ''));

$migrationGate  = $migrationGate ?? ['ready'=>true,'blocked_message'=>null,'migrations'=>[],'total'=>0,'completed'=>0];
$migrationReady = (bool)($migrationGate['ready'] ?? false);
$migrationRows  = $migrationGate['migrations'] ?? [];

/**
 * Translation helpers. Every translated node carries data-i18n so the browser can
 * switch language in place; the server renders the chosen language first.
 */
// <tag data-i18n="key">text</tag>
$tr = static function (string $tag, string $key, array $args = [], string $attrs = '') use ($lang): string {
    $data = $args ? ' data-i18n-args="' . esc(json_encode($args), 'attr') . '"' : '';

    return '<' . $tag . ($attrs !== '' ? ' ' . $attrs : '') . ' data-i18n="' . esc($key, 'attr') . '"' . $data . '>'
        . esc(Ui::t($lang, $key, $args)) . '</' . $tag . '>';
};
// Translated attributes: $ta('placeholder:ph_login', 'aria-label:x') => placeholder="..." data-i18n-attr="..."
$ta = static function (string ...$pairs) use ($lang): string {
    $out = [];
    foreach ($pairs as $pair) {
        [$attr, $key] = explode(':', $pair, 2);
        $out[] = $attr . '="' . esc(Ui::t($lang, $key), 'attr') . '"';
    }

    return implode(' ', $out) . ' data-i18n-attr="' . esc(implode(';', $pairs), 'attr') . '"';
};
// A message produced by the server: translated when known, shown as-is otherwise.
$msg = static function (string $message, string $tag = 'div', string $attrs = '') use ($tr): string {
    $key = Ui::keyForMessage($message);

    return $key !== null ? $tr($tag, $key, [], $attrs) : '<' . $tag . ($attrs !== '' ? ' ' . $attrs : '') . '>' . esc($message) . '</' . $tag . '>';
};
$alert = static fn (string $type, string $icon, string $body, string $align = 'center', string $iconClass = ''): string =>
    '<div class="alert alert-' . $type . ' d-flex align-items-' . $align . '" role="alert"><i class="bi bi-' . $icon . ' me-2' . $iconClass . '"></i>' . $body . '</div>';
?>
<!DOCTYPE html>
<html lang="<?= esc($lang, 'attr') ?>" dir="<?= esc($meta['dir'], 'attr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(($title ?? 'Login') . ' — ' . $companyName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;700;800&family=DM+Sans:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@500&family=Noto+Naskh+Arabic:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= base_url('css/lpg-theme.css') ?>">
    <script src="<?= base_url('js/lpg-busy.js') ?>?v=<?= (int) @filemtime(FCPATH . 'js/lpg-busy.js') ?>"></script>
</head>
<body class="login-page font-system">
<div class="wl">
    <header class="wl-top"><div class="wl-in">
        <div class="wl-brand"><span class="logo-mark"><i class="bi bi-fire"></i></span>Perfect LPG</div>
        <div class="wl-tools">
            <div class="wl-lang" role="group" <?= $ta('aria-label:lang_label') ?>>
                <?php foreach (Ui::supported() as $code) : ?>
                    <button type="button" lang="<?= esc($code, 'attr') ?>" data-lang="<?= esc($code, 'attr') ?>" aria-pressed="<?= $code === $lang ? 'true' : 'false' ?>"><?= esc(Ui::meta($code)['name']) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="wl-clock" aria-live="off"><b id="wlTime"><?= esc($now['time']) ?></b><span id="wlDate"><?= esc($now['date']) ?></span></div>
        </div>
    </div></header>

    <main class="wl-main"><div class="wl-in">
        <section>
            <span class="wl-eyebrow"><i></i><?= esc($shopName) ?></span>
            <h1><?= $tr('span', 'hero_1') ?> <em><?= $tr('span', 'hero_2') ?></em> <?= $tr('span', 'hero_3') ?></h1>
            <p class="wl-ur" lang="ur" dir="rtl">آپ کی دکان، آپ کا حساب۔</p>

            <div class="wl-card">
            <?= $tr('h2', 'signin_title') ?>
            <?= $tr('p', 'signin_sub', [], 'class="wl-sub"') ?>

            <?php if (session()->getFlashdata('migration_success')) : ?>
                <?= $alert('success', 'check-circle-fill', $msg((string) session()->getFlashdata('migration_success'))) ?>
            <?php endif; ?>

            <?php if (session()->getFlashdata('migration_error')) : ?>
                <?= $alert('danger', 'exclamation-octagon-fill', $msg((string) session()->getFlashdata('migration_error')), 'start', ' mt-1') ?>
            <?php endif; ?>

            <?php if (!$migrationReady) : ?>
                <div class="alert alert-warning d-flex align-items-start" role="alert">
                    <i class="bi bi-database-exclamation me-2 mt-1"></i>
                    <div>
                        <?= $tr('strong', 'gate_title') ?>
                        <?= $msg((string)($migrationGate['blocked_message'] ?? Ui::t(Ui::DEFAULT_LANG, 'msg_gate_default')), 'div', 'class="small mt-1"') ?>
                        <?php if (!empty($migrationGate['system_error'])) : ?>
                            <div class="small mt-1 text-danger"><?= esc($migrationGate['system_error']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($migrationRows) : ?>
                    <div class="table-responsive migration-list mb-3">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                            <tr>
                                <?= $tr('th', 'th_seq', [], 'style="width:70px"') ?>
                                <?= $tr('th', 'th_migration') ?>
                                <?= $tr('th', 'th_status', [], 'style="width:120px"') ?>
                                <?= $tr('th', 'th_result') ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($migrationRows as $m) : ?>
                                <?php
                                $status = (string)($m['status'] ?? 'pending');
                                $badge  = [
                                    'success' => 'success',
                                    'failed'  => 'danger',
                                    'running' => 'warning',
                                    'changed' => 'danger',
                                    'skipped' => 'warning',
                                    'pending' => 'secondary',
                                ][$status] ?? 'secondary';
                                ?>
                                <tr>
                                    <td><?= (int)$m['seq'] ?></td>
                                    <td class="small"><?= esc($m['file']) ?></td>
                                    <td><span class="badge text-bg-<?= $badge ?>"><?= esc(strtoupper($status)) ?></span></td>
                                    <td class="small"><?= esc($m['message'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <form action="<?= site_url('migrations/run') ?>" method="post" data-busy="gate_busy">
                    <?= csrf_field() ?>
                    <button type="submit" class="gp-btn w-100">
                        <i class="bi bi-database-up me-1"></i>
                        <?= $tr('span', 'gate_run') ?>
                    </button>
                </form>

                <div class="text-center text-muted small mt-3">
                    <?= $tr('span', 'gate_progress', ['done' => (int)($migrationGate['completed'] ?? 0), 'total' => (int)($migrationGate['total'] ?? 0)]) ?>
                    <?= $tr('span', 'gate_order') ?>
                </div>
            <?php endif; ?>

            <?php if ($migrationReady) : ?>
                <?php if (session()->getFlashdata('error')) : ?>
                    <?= $alert('danger', 'exclamation-triangle-fill', $msg((string) session()->getFlashdata('error'))) ?>
                <?php endif; ?>

                <?php if (session()->getFlashdata('success')) : ?>
                    <?= $alert('success', 'check-circle-fill', $msg((string) session()->getFlashdata('success'))) ?>
                <?php endif; ?>

                <?php if (session()->getFlashdata('errors')) : ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            <?php foreach (session()->getFlashdata('errors') as $error) : ?>
                                <?= $msg((string) $error, 'li') ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="<?= site_url('login') ?>" method="post" novalidate>
                    <?= csrf_field() ?>

                    <?= $tr('label', 'label_login', [], 'for="login"') ?>
                    <input type="text" class="form-control" id="login" name="login" dir="ltr"
                           value="<?= esc(old('login')) ?>" <?= $ta('placeholder:ph_login') ?> required autofocus>

                    <?= $tr('label', 'label_password', [], 'for="password"') ?>
                    <div class="password-field">
                        <input type="password" class="form-control" id="password" name="password" dir="ltr"
                               <?= $ta('placeholder:ph_password') ?> autocomplete="current-password" required>
                        <button type="button" class="password-toggle" id="togglePassword"
                                <?= $ta('aria-label:show_password') ?> aria-pressed="false" aria-controls="password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="login-meta">
                        <label class="remember-me" for="remember">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1"> <?= $tr('span', 'remember') ?>
                        </label>
                    </div>

                    <button type="submit" class="gp-btn"><?= $tr('span', 'submit') ?></button>
                </form>
                <?= $tr('p', 'help', [], 'class="wl-help"') ?>
            <?php endif; ?>
            </div>
        </section>

        <section class="wl-stage" <?= $ta('aria-label:stage_label') ?>>
            <div class="wl-glow"></div>
            <div class="wl-stat wl-s1"><?= $tr('small', 'chip_branch') ?><b><?= esc($shopName) ?></b></div>
            <div class="wl-stat wl-s2"><?= $tr('small', 'chip_system') ?><?= $tr('b', $migrationReady ? 'chip_ready' : 'chip_update', [], 'class="' . ($migrationReady ? 'ok' : 'warn') . '"') ?></div>
            <div class="wl-cyls" role="img" <?= $ta('aria-label:cyl_alt') ?>>
                <div><?= view('partials/cylinder_svg', ['fill' => 78]) ?></div>
                <div><?= view('partials/cylinder_svg', ['fill' => 92]) ?></div>
                <div><?= view('partials/cylinder_svg', ['fill' => 35]) ?></div>
            </div>
            <div class="wl-floor"></div>
        </section>
    </div></main>

    <footer class="wl-foot"><div class="wl-in">
        <div class="grp">
            <?php if ($shopPlace !== '') : ?><span><?= $tr('b', 'f_shop') ?> <?= esc($shopPlace) ?></span><?php endif; ?>
            <?php if ($shopPhone !== '') : ?><span><?= $tr('b', 'f_call') ?> <a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $shopPhone), 'attr') ?>" dir="ltr"><?= esc($shopPhone) ?></a></span><?php endif; ?>
            <span><b><?= esc($companyName) ?></b></span>
        </div>
        <span class="wl-health <?= $migrationReady ? '' : 'bad' ?>"><i></i><?= $tr('span', $migrationReady ? 'health_ok' : 'health_bad') ?></span>
    </div></footer>
</div>

<script type="application/json" id="wl-i18n"><?= json_encode(Ui::clientPayload($lang), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    'use strict';
    var node = document.getElementById('wl-i18n');
    if (!node) return;

    var cfg = JSON.parse(node.textContent);
    var lang = cfg.lang;
    var root = document.documentElement;
    var COOKIE = <?= json_encode(Ui::COOKIE) ?>;
    var timeEl = document.getElementById('wlTime');
    var dateEl = document.getElementById('wlDate');
    var pwInput = document.getElementById('password');
    var pwButton = document.getElementById('togglePassword');

    function each(selector, fn) { Array.prototype.forEach.call(document.querySelectorAll(selector), fn); }
    function readJson(el, name) { try { return JSON.parse(el.getAttribute(name)); } catch (e) { return null; } }

    // Same lookup order as UiLanguage::t(): chosen language, then English, then the key.
    function t(key, args) {
        var text = (cfg.catalog[lang] || {})[key];
        if (text === undefined) text = cfg.catalog[cfg.fallback][key];
        if (text === undefined) text = key;
        if (args) Object.keys(args).forEach(function (name) { text = text.split('{' + name + '}').join(args[name]); });
        return text;
    }

    function syncPasswordToggle() {
        if (pwInput && pwButton) pwButton.setAttribute('aria-label', t(pwInput.type === 'text' ? 'hide_password' : 'show_password'));
    }

    function renderClock() {
        var now = new Date(), locale = cfg.locales[lang];
        if (timeEl) timeEl.textContent = now.toLocaleTimeString(locale.time, {timeStyle: 'short'});
        if (dateEl) dateEl.textContent = now.toLocaleDateString(locale.date, {dateStyle: 'full'});
    }

    function apply(next) {
        lang = next;
        root.lang = lang;
        root.dir = cfg.locales[lang].dir;
        each('[data-i18n]', function (el) { el.textContent = t(el.getAttribute('data-i18n'), readJson(el, 'data-i18n-args')); });
        each('[data-i18n-attr]', function (el) {
            el.getAttribute('data-i18n-attr').split(';').forEach(function (pair) {
                var at = pair.indexOf(':');
                el.setAttribute(pair.slice(0, at), t(pair.slice(at + 1)));
            });
        });
        each('[data-lang]', function (btn) { btn.setAttribute('aria-pressed', btn.getAttribute('data-lang') === lang ? 'true' : 'false'); });
        syncPasswordToggle();
        renderClock();
    }

    each('[data-lang]', function (btn) {
        btn.addEventListener('click', function () {
            var next = btn.getAttribute('data-lang');
            if (next === lang || !cfg.catalog[next]) return;
            apply(next);
            try { document.cookie = COOKIE + '=' + next + '; path=/; max-age=31536000; SameSite=Lax'; } catch (e) { /* choice still applies for this visit */ }
        });
    });

    if (pwInput && pwButton) {
        pwButton.addEventListener('click', function () {
            var show = pwInput.type === 'password';
            pwInput.type = show ? 'text' : 'password';
            pwButton.setAttribute('aria-pressed', show ? 'true' : 'false');
            pwButton.firstElementChild.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            syncPasswordToggle();
            pwInput.focus();
        });
    }

    // Migration runner: block double submits and show progress in the current language.
    each('form[data-busy]', function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type=submit]');
            if (!button) return;
            var spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-1';
            button.disabled = true;
            button.textContent = '';
            button.appendChild(spinner);
            button.appendChild(document.createTextNode(t(form.getAttribute('data-busy'))));
        });
    });

    // Tick on the minute boundary instead of polling, and catch up when the tab wakes.
    (function loop() {
        renderClock();
        setTimeout(loop, 60000 - (Date.now() % 60000) + 50);
    })();
    document.addEventListener('visibilitychange', function () { if (!document.hidden) renderClock(); });
})();
</script>
</body>
</html>
