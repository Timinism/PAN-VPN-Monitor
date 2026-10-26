<?php

declare(strict_types=1);

use LibreNmsPlugin\PanosVpn\View\Format;

/** @var array<string, mixed> $vm */
/** @var string $css */

$h = static fn (?string $value): string => Format::h($value);
$wanted = $vm['filters']['fw'] ?? null;
$firewall = null;
foreach ($vm['firewalls'] as $row) {
    if ($wanted === null || (int) $row['device_id'] === (int) $wanted) {
        $firewall = $row;
        break;
    }
}
$page = (string) ($vm['page_url'] ?? $vm['base']);
?>
<section id="pv-root" class="pv pv-device">
<style><?= $css ?></style>
<header>
    <strong>Palo Alto VPN</strong>
    <?php if ($firewall): ?>
        <a href="<?= $h($page.((str_contains($page, '?') ? '&' : '?').'fw='.(int) $firewall['device_id'])) ?>">Open full page</a>
    <?php endif; ?>
</header>
<?php if (! $firewall): ?>
    <div class="pv-empty">No VPN poll is stored for this firewall yet. Add its API key in the Palo Alto VPN plugin settings.</div>
<?php else: ?>
    <div class="pv-device-meta">
        <?php if (! empty($firewall['last_error'])): ?>
            <?= $h((string) $firewall['last_error']) ?>
        <?php else: ?>
            <?= (int) $firewall['tunnel_up'] ?>/<?= (int) $firewall['tunnel_total'] ?> tunnels up
            · <?= $h(Format::rate($firewall['bps_in'])) ?> in
            · <?= $h(Format::rate($firewall['bps_out'])) ?> out
            · <?= (int) $firewall['gp_users'] ?> GlobalProtect users
            · <?= $h(Format::ago($firewall['last_polled_at'], (int) $vm['now'])) ?>
        <?php endif; ?>
    </div>
    <?php if ($vm['tunnels'] === []): ?>
        <div class="pv-empty">No site-to-site tunnels in the last poll.</div>
    <?php else: ?>
        <ul>
            <?php foreach ($vm['tunnels'] as $tunnel): ?>
                <li>
                    <i class="pv-dot" style="color: <?= Format::stateTone((string) $tunnel['state']) === 'up' ? 'var(--up)' : (Format::stateTone((string) $tunnel['state']) === 'init' ? 'var(--init)' : 'var(--down)') ?>"></i>
                    <span title="<?= $h((string) $tunnel['name']) ?>"><?= $h((string) $tunnel['name']) ?></span>
                    <span class="peer"><?= $h((string) ($tunnel['peer_ip'] ?? '—')) ?></span>
                    <span class="pv-num"><?= $h(Format::rate($tunnel['bps_in'] === null || $tunnel['bps_in'] === '' ? null : (int) $tunnel['bps_in'])) ?> / <?= $h(Format::rate($tunnel['bps_out'] === null || $tunnel['bps_out'] === '' ? null : (int) $tunnel['bps_out'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($vm['gateway_choices'] !== []): ?>
        <ul class="pv-device-gates">
            <?php foreach ($vm['gateway_choices'] as $gateway): ?>
                <li>
                    <span><?= $h((string) $gateway['name']) ?></span>
                    <strong><?= (int) $gateway['current_users'] ?> users</strong>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
</section>
