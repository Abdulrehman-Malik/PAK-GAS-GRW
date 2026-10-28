<?php
/** English / Urdu switch (links, so it works without JavaScript). Expects: $uiLang (string). */
use App\Libraries\UiLanguage as Ui;

$uiLang = Ui::resolve($uiLang ?? Ui::DEFAULT_LANG);
?>
<div class="lang-switch" role="group" aria-label="Language" data-no-i18n>
    <?php foreach (Ui::supported() as $code) : ?>
        <a href="<?= site_url('language/' . $code) ?>" lang="<?= esc($code, 'attr') ?>"<?= $code === $uiLang ? ' aria-current="true"' : '' ?>><?= esc(Ui::meta($code)['name']) ?></a>
    <?php endforeach; ?>
</div>
