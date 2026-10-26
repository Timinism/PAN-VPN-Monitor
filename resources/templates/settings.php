<?php

declare(strict_types=1);

use LibreNmsPlugin\PanosVpn\View\Format;

/** @var array<string, mixed> $vm */
/** @var string $css */

$h = static fn (?string $value): string => Format::h($value);
$settings = $vm['settings'];
$devices = $settings['devices'] ?? [];
?>
<div id="pv-root" class="pv pv-settings">
<style><?= $css ?></style>
<div>
    <p class="pv-kicker">Palo Alto VPN</p>
    <h1>Settings</h1>
    <p class="pv-lede">API keys stay in the LibreNMS plugin settings. Use a read-only admin role with XML API operational requests enabled. This plugin never sends configuration changes.</p>
</div>

<?php if (! empty($vm['notice'])): ?>
    <div class="pv-alert"><?= $h((string) $vm['notice']) ?></div>
<?php endif; ?>

<form method="post" action="<?= $h((string) $vm['action']) ?>">
    <input type="hidden" name="_token" value="<?= $h((string) ($vm['csrf'] ?? '')) ?>">
    <section class="pv-card">
        <h2>Poller</h2>
        <p class="pv-help">Traffic in is decapsulated bytes. Traffic out is encapsulated bytes. When several proxy-IDs share one tunnel interface, the poller asks for per-tunnel counters and falls back to the interface total.</p>
        <div class="pv-form-grid">
            <label>Timeout (seconds)
                <input type="number" name="settings[timeout]" min="3" max="120" value="<?= (int) ($settings['timeout'] ?? 20) ?>">
            </label>
            <label>Keep history (days)
                <input type="number" name="settings[retention_days]" min="1" max="90" value="<?= (int) ($settings['retention_days'] ?? 14) ?>">
            </label>
            <div>
                <input type="hidden" name="settings[verify_tls]" value="0">
                <label class="pv-check"><input type="checkbox" name="settings[verify_tls]" value="1" <?= ! empty($settings['verify_tls']) ? 'checked' : '' ?>> Verify firewall TLS certificates</label>
                <input type="hidden" name="settings[per_tunnel_counters]" value="0">
                <label class="pv-check"><input type="checkbox" name="settings[per_tunnel_counters]" value="1" <?= ! array_key_exists('per_tunnel_counters', $settings) || ! empty($settings['per_tunnel_counters']) ? 'checked' : '' ?>> Per-tunnel counters when an interface is shared</label>
            </div>
        </div>
    </section>

    <section class="pv-card" style="margin-top: 12px;">
        <h2>Firewalls</h2>
        <p class="pv-help">Only devices LibreNMS already identifies as PAN-OS are listed. Leave the host blank to use the device hostname. Set a Panorama serial in Target when the API call goes through Panorama.</p>
        <?php if (($vm['devices'] ?? []) === []): ?>
            <div class="pv-empty">No PAN-OS devices are in this LibreNMS install yet.</div>
        <?php else: ?>
            <?php foreach ($vm['devices'] as $device): ?>
                <?php
                $id = (int) $device['device_id'];
                $saved = $devices[(string) $id] ?? $devices[$id] ?? [];
                $hasKey = ($saved['api_key'] ?? '') !== '';
                ?>
                <article class="pv-firewall">
                    <header>
                        <div>
                            <b><?= $h((string) ($device['display_name'] ?: $device['hostname'])) ?></b>
                            <div class="pv-sub"><?= $h((string) $device['hostname']) ?></div>
                        </div>
                        <input type="hidden" name="settings[devices][<?= $id ?>][enabled]" value="0">
                        <label class="pv-check"><input type="checkbox" name="settings[devices][<?= $id ?>][enabled]" value="1" <?= ! empty($saved['enabled']) ? 'checked' : '' ?>> Poll this firewall</label>
                    </header>
                    <div class="pv-form-grid">
                        <label class="pv-key">API key
                            <input type="password" name="settings[devices][<?= $id ?>][api_key]" value="" placeholder="<?= $hasKey ? 'Key saved' : 'Paste API key' ?>" autocomplete="off">
                        </label>
                        <label>Host override
                            <input type="text" name="settings[devices][<?= $id ?>][host]" value="<?= $h((string) ($saved['host'] ?? '')) ?>" placeholder="Use the LibreNMS hostname">
                        </label>
                        <label>Port
                            <input type="number" name="settings[devices][<?= $id ?>][port]" min="1" max="65535" value="<?= (int) ($saved['port'] ?? 443) ?>">
                        </label>
                        <label>Panorama serial
                            <input type="text" name="settings[devices][<?= $id ?>][target]" value="<?= $h((string) ($saved['target'] ?? '')) ?>" placeholder="Only when polling through Panorama">
                        </label>
                        <label class="pv-check"><input type="checkbox" name="settings[devices][<?= $id ?>][clear_key]" value="1"> Remove saved key</label>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="pv-actions">
            <button class="pv-btn" type="submit">Save settings</button>
        </div>
    </section>
</form>

<?php if (! empty($vm['test_action']) && ($vm['devices'] ?? []) !== []): ?>
    <section class="pv-card">
        <h2>Test a firewall</h2>
        <p class="pv-help">Runs <code>show vpn flow</code> and reports how many site-to-site tunnels came back. A blank key tests the saved key.</p>
        <form method="post" action="<?= $h((string) $vm['test_action']) ?>">
            <input type="hidden" name="_token" value="<?= $h((string) ($vm['csrf'] ?? '')) ?>">
            <div class="pv-form-grid">
                <label>Device
                    <select name="device_id" style="width:100%; padding:8px; border:1px solid var(--line); border-radius:6px; font:inherit;">
                        <?php foreach ($vm['devices'] as $device): ?>
                            <option value="<?= (int) $device['device_id'] ?>"><?= $h((string) ($device['display_name'] ?: $device['hostname'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>API key override
                    <input type="password" name="api_key" value="" placeholder="Optional" autocomplete="off">
                </label>
                <div class="pv-actions">
                    <button class="pv-btn secondary" type="submit">Test connection</button>
                </div>
            </div>
        </form>
    </section>
<?php endif; ?>
</div>
