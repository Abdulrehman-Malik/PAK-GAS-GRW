<?php
/**
 * Head assets for a non-English interface language. Renders nothing for English,
 * so English users load no extra files. Expects: $uiLang (string).
 */
use App\Libraries\UiLanguage as Ui;

$uiLang = Ui::resolve($uiLang ?? Ui::DEFAULT_LANG);
if ($uiLang === Ui::DEFAULT_LANG) {
    return;
}
?>
<link href="https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= base_url('css/lpg-rtl.css') ?>?v=<?= (int) @filemtime(FCPATH . 'css/lpg-rtl.css') ?>">
<script>
// Hidden until the translator has run; never stay hidden if it fails to load.
document.documentElement.classList.add('i18n-pending');
setTimeout(function () { document.documentElement.classList.remove('i18n-pending'); }, 1500);
</script>
<script src="<?= site_url('i18n/' . $uiLang . '.js') ?>?v=<?= esc(Ui::appVersion($uiLang), 'url') ?>"></script>
