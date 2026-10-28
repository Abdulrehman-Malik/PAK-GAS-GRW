<?php
// Emerald LPG cylinder with gold valve/base and a gas-level tint. Self-contained:
// gradient ids are unique per instance so several cylinders can share one page.
$cid = 'cyl' . substr(md5(uniqid('', true)), 0, 8);
$fill = max(0, min(100, (float)($fill ?? 70)));
$top = 96;
$bot = 262;
$levelY = round($bot - ($bot - $top) * $fill / 100, 1);
$body = 'M14 112c0-30 20-44 46-44s46 14 46 44v132c0 14-10 24-24 24H38c-14 0-24-10-24-24z';
?>
<svg viewBox="0 0 120 290" aria-hidden="true">
    <defs>
        <linearGradient id="<?= $cid ?>s" x1="0" x2="1"><stop offset="0" stop-color="#7D8F88"/><stop offset=".22" stop-color="#E4EDE9"/><stop offset=".45" stop-color="#fff"/><stop offset=".78" stop-color="#B2C2BB"/><stop offset="1" stop-color="#6A7C75"/></linearGradient>
        <linearGradient id="<?= $cid ?>e" x1="0" x2="1"><stop offset="0" stop-color="#04261E"/><stop offset=".4" stop-color="#14966F"/><stop offset="1" stop-color="#04261E"/></linearGradient>
        <linearGradient id="<?= $cid ?>g" x1="0" x2="1"><stop offset="0" stop-color="#B9820D"/><stop offset=".5" stop-color="#F9DA8A"/><stop offset="1" stop-color="#B9820D"/></linearGradient>
        <clipPath id="<?= $cid ?>c"><path d="<?= $body ?>"/></clipPath>
    </defs>
    <rect x="42" y="14" width="36" height="14" rx="4" fill="url(#<?= $cid ?>g)"/>
    <rect x="50" y="26" width="20" height="22" fill="url(#<?= $cid ?>s)"/>
    <path d="M30 56c8-8 52-8 60 0v12H30z" fill="url(#<?= $cid ?>s)"/>
    <path d="<?= $body ?>" fill="url(#<?= $cid ?>e)"/>
    <g clip-path="url(#<?= $cid ?>c)">
        <rect class="lv" x="0" y="<?= $levelY ?>" width="120" height="<?= round($bot - $levelY + 10, 1) ?>" fill="#F2B544" opacity=".28"/>
        <rect x="0" y="<?= $levelY ?>" width="120" height="2" fill="#F9DA8A" opacity=".7"/>
    </g>
    <rect x="30" y="140" width="60" height="64" rx="8" fill="rgba(255,255,255,.12)" stroke="rgba(255,255,255,.35)"/>
    <text x="60" y="168" text-anchor="middle" font-family="Bricolage Grotesque,sans-serif" font-weight="800" font-size="13" fill="#fff">PERFECT</text>
    <text x="60" y="190" text-anchor="middle" font-family="Bricolage Grotesque,sans-serif" font-weight="800" font-size="20" fill="#F9DA8A">LPG</text>
    <path d="M24 108c0-24 14-34 36-34" stroke="rgba(255,255,255,.35)" stroke-width="5" fill="none" stroke-linecap="round"/>
    <rect x="20" y="266" width="80" height="14" rx="5" fill="url(#<?= $cid ?>g)"/>
</svg>
