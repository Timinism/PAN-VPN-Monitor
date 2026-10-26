<?php

declare(strict_types=1);

use LibreNmsPlugin\PanosVpn\View\Format;

/** @var array<string, mixed> $vm */
/** @var string $css */

$h = static fn (?string $value): string => Format::h($value);
$filters = $vm['filters'];
$link = static function (array $overrides = []) use ($vm, $filters, $h): string {
    $query = [
        'fw' => $filters['fw'],
        'state' => $filters['state'],
        'gw' => $filters['gw'],
        'q' => $filters['q'],
    ];
    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }
    $clean = [];
    foreach ($query as $key => $value) {
        if ($value === null || $value === '' || $value === 'all' || $value === 0) {
            continue;
        }
        $clean[$key] = $value;
    }
    $qs = http_build_query($clean);
    $base = (string) $vm['base'];

    return $h($base.($qs === '' ? '' : (str_contains($base, '?') ? '&' : '?').$qs));
};
$deviceBase = rtrim((string) ($vm['device_base'] ?? '/device'), '/');
$upCount = 0;
$downCount = 0;
foreach ($vm['tunnels'] as $tunnel) {
    if (strtolower((string) $tunnel['state']) === 'active') {
        $upCount++;
    } else {
        $downCount++;
    }
}
?>
<div id="pv-root" class="pv">
<style><?= $css ?></style>
<header class="pv-head">
    <div>
        <p class="pv-kicker">Palo Alto</p>
        <h1>VPN</h1>
        <p class="pv-lede">Site-to-site tunnels, throughput per tunnel, and GlobalProtect users on each gateway.</p>
    </div>
    <form class="pv-search" method="get" action="<?= $h((string) $vm['base']) ?>">
        <?php if ($filters['fw']): ?><input type="hidden" name="fw" value="<?= (int) $filters['fw'] ?>"><?php endif; ?>
        <?php if ($filters['state'] !== 'all'): ?><input type="hidden" name="state" value="<?= $h((string) $filters['state']) ?>"><?php endif; ?>
        <?php if ($filters['gw'] !== ''): ?><input type="hidden" name="gw" value="<?= $h((string) $filters['gw']) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= $h((string) $filters['q']) ?>" placeholder="Tunnel, peer, user, gateway" aria-label="Filter VPN data">
        <button type="submit">Filter</button>
    </form>
</header>

<?php if (! $vm['configured']): ?>
    <div class="pv-card pv-empty">
        <p>No Palo Alto firewalls have been polled yet. Add an API key for each PAN-OS device in the plugin settings. The key only needs operational XML API access.</p>
    </div>
<?php else: ?>
    <?php
    $alerts = [];
    foreach ($vm['firewalls'] as $firewall) {
        if ($filters['fw'] && (int) $filters['fw'] !== $firewall['device_id']) {
            continue;
        }
        if (! empty($firewall['last_error'])) {
            $alerts[] = ['tone' => 'pv-alert', 'text' => $firewall['display_name'].' — '.$firewall['last_error']];
        } elseif (! empty($firewall['last_warning'])) {
            $alerts[] = ['tone' => 'pv-alert warn', 'text' => $firewall['display_name'].' — '.$firewall['last_warning']];
        }
    }
    ?>
    <?php if ($alerts !== []): ?>
        <div class="pv-alerts">
            <?php foreach ($alerts as $alert): ?>
                <div class="<?= $h($alert['tone']) ?>"><?= $h($alert['text']) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="pv-stats" aria-label="VPN summary">
        <?php foreach ($vm['stats'] as $stat): ?>
            <article class="pv-stat <?= $h((string) $stat['tone']) ?>">
                <span><?= $h((string) $stat['label']) ?></span>
                <strong><?= $h((string) $stat['value']) ?></strong>
                <em><?= $h((string) $stat['hint']) ?></em>
            </article>
        <?php endforeach; ?>
    </section>

    <nav class="pv-chips" aria-label="Firewalls">
        <a class="pv-chip<?= $filters['fw'] ? '' : ' active' ?>" href="<?= $link(['fw' => null, 'gw' => null]) ?>">
            <b>All firewalls</b>
            <small><?= count($vm['firewalls']) ?> PAN-OS devices</small>
        </a>
        <?php foreach ($vm['firewalls'] as $firewall): ?>
            <a class="pv-chip<?= $firewall['active'] ? ' active' : '' ?><?= $firewall['last_error'] ? ' error' : '' ?>" href="<?= $link(['fw' => $firewall['device_id'], 'gw' => null]) ?>">
                <b><?= $h($firewall['display_name']) ?></b>
                <small>
                    <?php if ($firewall['last_error']): ?>
                        Poll failed · <?= $h(Format::ago($firewall['last_polled_at'], (int) $vm['now'])) ?>
                    <?php else: ?>
                        <?= (int) $firewall['tunnel_up'] ?>/<?= (int) $firewall['tunnel_total'] ?> up
                        · <?= $h(Format::rate($firewall['bps_in'])) ?> in
                        · <?= (int) $firewall['gp_users'] ?> GP
                    <?php endif; ?>
                </small>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="pv-section">
        <div class="pv-section-head">
            <div>
                <span class="pv-section-kicker">Site-to-site</span>
                <h2><?= $upCount ?> up · <?= $downCount ?> down</h2>
            </div>
            <div class="pv-filters" role="tablist" aria-label="Tunnel state">
                <a class="<?= $filters['state'] === 'all' ? 'active' : '' ?>" href="<?= $link(['state' => 'all']) ?>">All</a>
                <a class="<?= $filters['state'] === 'up' ? 'active' : '' ?>" href="<?= $link(['state' => 'up']) ?>">Up</a>
                <a class="<?= $filters['state'] === 'down' ? 'active' : '' ?>" href="<?= $link(['state' => 'down']) ?>">Down</a>
            </div>
        </div>
        <div class="pv-table-card">
            <?php if ($vm['tunnels'] === []): ?>
                <div class="pv-empty">No site-to-site tunnels match this filter.</div>
            <?php else: ?>
                <table class="pv-table">
                    <thead>
                        <tr>
                            <th>State</th>
                            <th>Firewall</th>
                            <th>Tunnel</th>
                            <th>Peer</th>
                            <th class="pv-num">In</th>
                            <th class="pv-num">Out</th>
                            <th>History</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vm['tunnels'] as $tunnel): ?>
                        <?php
                        $tone = Format::stateTone((string) $tunnel['state']);
                        $in = [];
                        $out = [];
                        foreach ($tunnel['samples'] as $sample) {
                            $in[] = $sample['bps_in'] === null ? null : (int) $sample['bps_in'];
                            $out[] = $sample['bps_out'] === null ? null : (int) $sample['bps_out'];
                        }
                        $shared = ($tunnel['bandwidth_scope'] ?? null) === 'interface';
                        ?>
                        <tr class="<?= $tone === 'up' ? '' : 'is-down' ?>">
                            <td><span class="pv-pill <?= $h($tone) ?>"><i class="pv-dot"></i><?= $h(Format::stateLabel((string) $tunnel['state'])) ?></span></td>
                            <td><a href="<?= $h($deviceBase.'/'.(int) $tunnel['device_id']) ?>"><?= $h((string) $tunnel['device_name']) ?></a></td>
                            <td>
                                <div class="pv-name"><?= $h((string) $tunnel['name']) ?><?php if ($shared): ?><span class="pv-badge">shared iface</span><?php endif; ?></div>
                                <div class="pv-sub"><?= $h((string) ($tunnel['inner_if'] ?? 'no tunnel interface')) ?><?= $tunnel['gateway'] ? ' · '.$h((string) $tunnel['gateway']) : '' ?></div>
                            </td>
                            <td>
                                <div><?= $h((string) ($tunnel['peer_ip'] ?? '—')) ?></div>
                                <div class="pv-sub"><?= $h((string) ($tunnel['monitor'] === 'on' ? 'monitor on' : ($tunnel['monitor'] === 'off' ? 'monitor off' : ''))) ?><?= $tunnel['monitor_status'] ? ' · '.$h((string) $tunnel['monitor_status']) : '' ?></div>
                            </td>
                            <td class="pv-num pv-in"><?= $h(Format::rate($tunnel['bps_in'] === null || $tunnel['bps_in'] === '' ? null : (int) $tunnel['bps_in'])) ?></td>
                            <td class="pv-num pv-out"><?= $h(Format::rate($tunnel['bps_out'] === null || $tunnel['bps_out'] === '' ? null : (int) $tunnel['bps_out'])) ?></td>
                            <td>
                                <?= Format::sparkline($in, $out) ?>
                                <details>
                                    <summary>Counters</summary>
                                    <p>
                                        In <?= $h(Format::bytes($tunnel['decap_bytes'] === null || $tunnel['decap_bytes'] === '' ? null : (int) $tunnel['decap_bytes'])) ?>
                                        decap · Out <?= $h(Format::bytes($tunnel['encap_bytes'] === null || $tunnel['encap_bytes'] === '' ? null : (int) $tunnel['encap_bytes'])) ?>
                                        encap<br>
                                        <?= $h(Format::crypto($tunnel['protocol'], $tunnel['encryption'], $tunnel['auth_hash'])) ?>
                                        <?php if ($tunnel['local_ip']): ?> · local <?= $h((string) $tunnel['local_ip']) ?><?php endif; ?>
                                        <?php if ($tunnel['outer_if']): ?> · <?= $h((string) $tunnel['outer_if']) ?><?php endif; ?>
                                        <?php if ($shared): ?><br>This rate is the <?= $h((string) $tunnel['inner_if']) ?> interface, shared by every proxy-ID on it.<?php endif; ?>
                                    </p>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <section class="pv-section">
        <div class="pv-section-head">
            <div>
                <span class="pv-section-kicker">GlobalProtect</span>
                <h2>Connected users by gateway</h2>
            </div>
            <div class="pv-filters">
                <a class="<?= $filters['gw'] === '' ? 'active' : '' ?>" href="<?= $link(['gw' => null]) ?>">All gateways</a>
            </div>
        </div>
        <?php if ($vm['gateway_choices'] === []): ?>
            <div class="pv-card pv-empty">No GlobalProtect gateways were returned. A site-to-site firewall with no remote-access gateway stays empty here.</div>
        <?php else: ?>
            <div class="pv-gates">
                <?php foreach ($vm['gateway_choices'] as $gateway): ?>
                    <?php
                    $active = $filters['gw'] === (string) $gateway['name'];
                    $max = $gateway['max_users'] !== null && (int) $gateway['max_users'] > 0 ? (int) $gateway['max_users'] : null;
                    $pct = $max ? min(100, (int) round(((int) $gateway['current_users'] / $max) * 100)) : null;
                    $series = array_map(static fn (array $sample): int => (int) $sample['current_users'], $gateway['samples']);
                    ?>
                    <a class="pv-gate<?= $active ? ' active' : '' ?>" href="<?= $link(['gw' => $active ? null : $gateway['name']]) ?>">
                        <b><?= $h((string) $gateway['name']) ?></b>
                        <div class="pv-sub"><?= $h((string) $gateway['device_name']) ?></div>
                        <div class="pv-count"><?= (int) $gateway['current_users'] ?></div>
                        <div class="pv-sub">
                            <?php if ($gateway['previous_users'] !== null && $gateway['previous_users'] !== ''): ?>
                                previous <?= (int) $gateway['previous_users'] ?>
                            <?php else: ?>
                                connected now
                            <?php endif; ?>
                            <?php if ($max): ?> · cap <?= $max ?><?php endif; ?>
                        </div>
                        <?php if ($pct !== null): ?><div class="pv-meter" title="<?= $pct ?>% of the gateway cap"><span style="width: <?= $pct ?>%"></span></div><?php endif; ?>
                        <?= Format::sparkline($series, []) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="pv-table-card">
                <table class="pv-table">
                    <thead>
                        <tr>
                            <th>Gateway</th>
                            <th>User</th>
                            <th>Computer</th>
                            <th>Client</th>
                            <th>Virtual IP</th>
                            <th>Public IP</th>
                            <th>Tunnel</th>
                            <th>Connected</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $shown = 0;
                    foreach ($vm['gateways'] as $gateway):
                        foreach ($gateway['users'] as $user):
                            if ($filters['q'] !== '') {
                                $haystack = strtolower(implode(' ', [
                                    (string) $gateway['name'],
                                    (string) $user['username'],
                                    (string) ($user['computer'] ?? ''),
                                    (string) ($user['public_ip'] ?? ''),
                                    (string) ($user['virtual_ip'] ?? ''),
                                ]));
                                if (! str_contains($haystack, strtolower((string) $filters['q']))) {
                                    continue;
                                }
                            }
                            $shown++;
                            $login = $user['login_time_utc'] !== null && $user['login_time_utc'] !== ''
                                ? Format::when((int) $user['login_time_utc'], (string) $vm['timezone'])
                                : (string) ($user['login_time'] ?? '—');
                            ?>
                            <tr>
                                <td><?= $h((string) $gateway['name']) ?><div class="pv-sub"><?= $h((string) $gateway['device_name']) ?></div></td>
                                <td class="pv-name"><?= $h((string) ($user['domain'] ? $user['domain'].'\\'.$user['username'] : $user['username'])) ?></td>
                                <td><?= $h((string) ($user['computer'] ?? '—')) ?></td>
                                <td><?= $h((string) ($user['client'] ?? '—')) ?></td>
                                <td><?= $h((string) ($user['virtual_ip'] ?? '—')) ?></td>
                                <td><?= $h((string) ($user['public_ip'] ?? '—')) ?></td>
                                <td><?= $h((string) ($user['tunnel_type'] ?? '—')) ?></td>
                                <td><?= $h($login) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php if ($shown === 0): ?>
                        <tr><td colspan="8"><div class="pv-empty">No connected users on this gateway.</div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
</div>
