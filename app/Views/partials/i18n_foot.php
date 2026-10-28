<?php
/** Translator script, last thing before </body>. Renders nothing for English. Expects: $uiLang (string). */
use App\Libraries\UiLanguage as Ui;

if (Ui::resolve($uiLang ?? Ui::DEFAULT_LANG) === Ui::DEFAULT_LANG) {
    return;
}
?>
<script src="<?= base_url('js/lpg-i18n.js') ?>?v=<?= esc(Ui::appVersion($uiLang), 'url') ?>-<?= (int) @filemtime(FCPATH . 'js/lpg-i18n.js') ?>"></script>
