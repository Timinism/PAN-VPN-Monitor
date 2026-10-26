<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use LibreNmsPlugin\PanosVpn\Panos\Client;
use LibreNmsPlugin\PanosVpn\Panos\PanosException;
use LibreNmsPlugin\PanosVpn\Panos\Parser;
use LibreNmsPlugin\PanosVpn\Panos\Poller;
use LibreNmsPlugin\PanosVpn\Panos\Snapshot;
use LibreNmsPlugin\PanosVpn\Polling\Engine;
use LibreNmsPlugin\PanosVpn\Polling\PdoConnection;
use LibreNmsPlugin\PanosVpn\Polling\Rate;
use LibreNmsPlugin\PanosVpn\Polling\Repository;
use LibreNmsPlugin\PanosVpn\Polling\Schema;
use LibreNmsPlugin\PanosVpn\Support\SettingsMerger;
use LibreNmsPlugin\PanosVpn\View\Format;
use LibreNmsPlugin\PanosVpn\View\Html;
use LibreNmsPlugin\PanosVpn\View\Presenter;

$failures = 0;
$checks = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok  {$name}\n";

        return;
    }
    $failures++;
    echo " FAIL {$name}".($detail !== '' ? " — {$detail}" : '')."\n";
}

final class MapClient implements Client
{
    /** @param  array<string, string>  $map */
    public function __construct(private array $map)
    {
        uksort($this->map, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
    }

    public function op(string $command): string
    {
        foreach ($this->map as $needle => $xml) {
            if (str_contains($command, $needle)) {
                return $xml;
            }
        }
        throw new PanosException('no fixture for '.$command);
    }
}

echo "parsers\n";

$flow = <<<'XML'
<response status="success"><result>
  <total>3</total>
  <num_ipsec>3</num_ipsec>
  <IPSec>
    <entry>
      <peerip>198.51.100.10</peerip>
      <name>HQ-to-DC</name>
      <outer-if>ethernet1/1</outer-if>
      <gwid>2</gwid>
      <localip>203.0.113.10</localip>
      <state>active</state>
      <inner-if>tunnel.10</inner-if>
      <mon>off</mon>
      <id>10</id>
      <encap-bytes>1000</encap-bytes>
      <decap-bytes>400</decap-bytes>
    </entry>
    <entry>
      <peerip>198.51.100.80</peerip>
      <name>HQ-to-Plant</name>
      <outer-if>ethernet1/1</outer-if>
      <localip>203.0.113.10</localip>
      <state>inactive</state>
      <inner-if>tunnel.13</inner-if>
      <mon>on</mon>
      <mon-status>down</mon-status>
      <id>13</id>
    </entry>
    <entry>
      <peerip>203.0.113.9</peerip>
      <name>HQ-to-Vendor</name>
      <state>init</state>
      <inner-if>tunnel.14</inner-if>
      <mon>off</mon>
      <id>14</id>
    </entry>
  </IPSec>
</result></response>
XML;

$tunnels = Parser::tunnels($flow);
check('flow count', count($tunnels) === 3, (string) count($tunnels));
check('active tunnel bytes', $tunnels[0]['encap_bytes'] === 1000 && $tunnels[0]['bandwidth_scope'] === 'tunnel');
check('monitor status', $tunnels[1]['monitor_status'] === 'down' && $tunnels[1]['state'] === 'inactive');
check('init has no counters', $tunnels[2]['encap_bytes'] === null && $tunnels[2]['state'] === 'init');

$planes = <<<'XML'
<response status="success"><result>
  <dp0><IPSec><entry>
    <name>dp0-tunnel</name><id>1</id><state>active</state>
    <peerip>198.51.100.1</peerip><localip>203.0.113.1</localip>
    <inner-if>tunnel.1</inner-if><outer-if>ethernet1/1</outer-if><mon>off</mon>
  </entry></IPSec></dp0>
  <dp1><IPSec><entry>
    <name>dp0-tunnel</name><id>2</id><state>inactive</state>
    <peerip>198.51.100.2</peerip><localip>203.0.113.1</localip>
    <inner-if>tunnel.2</inner-if><outer-if>ethernet1/2</outer-if><mon>on</mon>
  </entry></IPSec></dp1>
</result></response>
XML;
$planeTunnels = Parser::tunnels($planes);
check('dataplane entries', count($planeTunnels) === 2);
check('duplicate names stay distinct', $planeTunnels[0]['name'] === 'dp0-tunnel' && $planeTunnels[1]['name'] === 'dp0-tunnel#2');

$sa = Parser::ipsecSa(<<<'XML'
<response status="success"><result>
  <ntun>1</ntun>
  <entries><entry>
    <name>HQ-to-DC</name><gateway>IKE-DC</gateway><proto>ESP</proto><enc>G256</enc><hash>SHA256</hash><tid>10</tid>
  </entry></entries>
</result></response>
XML);
check('ipsec sa gateway', ($sa['HQ-to-DC']['gateway'] ?? '') === 'IKE-DC' && $sa['HQ-to-DC']['encryption'] === 'G256');

$detail = Parser::flowBytes(<<<'XML'
<response status="success"><result><entry>
  <id>13</id><encap-bytes>50</encap-bytes><decap-bytes>25</decap-bytes>
</entry></result></response>
XML);
check('flow detail bytes', $detail['encap'] === 50 && $detail['decap'] === 25);

$iface = Parser::interfaceBytes(<<<'XML'
<response status="success"><result><hw><counters>
  <entry><name>bytes received</name><value>800</value></entry>
  <entry><name>bytes transmitted</name><value>1600</value></entry>
</counters></hw></result></response>
XML);
check('interface bytes map decap/encap', $iface['decap'] === 800 && $iface['encap'] === 1600);

$stats = Parser::gatewayStats(<<<'XML'
<response status="success"><result>
  <entry name="GP-External"><gateway>GP-External</gateway><current-users>36</current-users><previous-users>34</previous-users></entry>
  <entry name="GP-Contractors"><CurrentUsers>7</CurrentUsers><max-users>50</max-users></entry>
</result></response>
XML);
check('gateway stats', count($stats) === 2 && $stats[0]['current_users'] === 36 && $stats[1]['max_users'] === 50);

$users = Parser::users(<<<'XML'
<response status="success"><result>
  <gateway name="GP-External">
    <entry>
      <username>noc-01</username><computer>WIN11-NOC-01</computer>
      <client>GlobalProtect 6.3</client><virtual-ip>10.50.0.11</virtual-ip>
      <public-ip>203.0.113.40</public-ip><tunnel-type>IPSec</tunnel-type>
      <login-time-utc>1761382800</login-time-utc>
    </entry>
  </gateway>
</result></response>
XML);
check('nested gp user', count($users) === 1 && $users[0]['gateway'] === 'GP-External' && $users[0]['virtual_ip'] === '10.50.0.11');

$flat = Parser::users(<<<'XML'
<response status="success"><result><entry>
  <username>field-02</username><virtual-ip>10.50.1.4</virtual-ip><public-ip>198.51.100.9</public-ip>
</entry></result></response>
XML, 'GP-Jump');
check('flat user uses default gateway', $flat[0]['gateway'] === 'GP-Jump' && $flat[0]['username'] === 'field-02');

$threw = false;
try {
    Parser::assertOk('<response status="error"><result><msg>Invalid credential</msg></result></response>');
} catch (PanosException $exception) {
    $threw = $exception->getMessage() === 'Invalid credential';
}
check('api error message', $threw);

echo "rates and store\n";
check('rate from counters', Rate::bps(1000, 6000, 10) === 4000);
check('counter reset skipped', Rate::bps(6000, 100, 10) === null);
check('first sample skipped', Rate::bps(null, 100, 10) === null);

$pdo = new PDO('sqlite::memory:');
Schema::install($pdo);
$repo = new Repository(new PdoConnection($pdo));
$engine = new Engine();

$sharedFlow = <<<'XML'
<response status="success"><result><IPSec>
  <entry><name>A</name><id>1</id><state>active</state><peerip>198.51.100.1</peerip><localip>203.0.113.1</localip><inner-if>tunnel.1</inner-if><mon>off</mon></entry>
  <entry><name>B</name><id>2</id><state>active</state><peerip>198.51.100.2</peerip><localip>203.0.113.1</localip><inner-if>tunnel.1</inner-if><mon>off</mon></entry>
  <entry><name>C</name><id>3</id><state>inactive</state><peerip>198.51.100.3</peerip><localip>203.0.113.1</localip><inner-if>tunnel.3</inner-if><mon>on</mon></entry>
</IPSec></result></response>
XML;
$emptyGp = '<response status="success"><result></result></response>';
$client = new MapClient([
    'tunnel-id>1' => '<response status="success"><result><encap-bytes>1000</encap-bytes><decap-bytes>500</decap-bytes></result></response>',
    'tunnel-id>2' => '<response status="success"><result><encap-bytes>3000</encap-bytes><decap-bytes>1500</decap-bytes></result></response>',
    '<flow></flow>' => $sharedFlow,
    'ipsec-sa' => '<response status="success"><result><entries><entry><name>A</name><gateway>IKE-A</gateway><proto>ESP</proto><enc>A256</enc><hash>SHA256</hash></entry></entries></result></response>',
    'statistics' => $emptyGp,
    '<gateway></gateway>' => $emptyGp,
    'current-user' => $emptyGp,
    '<interface>tunnel.3</interface>' => '<response status="success"><result><ibytes>10</ibytes><obytes>20</obytes></result></response>',
]);
$snapshot = (new Poller())->collect($client, true);
check('shared tunnels get their own counters', $snapshot->tunnels[0]['bandwidth_scope'] === 'tunnel' && $snapshot->tunnels[0]['encap_bytes'] === 1000 && $snapshot->tunnels[1]['encap_bytes'] === 3000);
check('single interface fallback', $snapshot->tunnels[2]['bandwidth_scope'] === 'interface' && $snapshot->tunnels[2]['decap_bytes'] === 10);
check('sa merged onto tunnel', $snapshot->tunnels[0]['gateway'] === 'IKE-A' && $snapshot->tunnels[0]['encryption'] === 'A256');

$engine->poll($client, $repo, 7, 'hq.example', 'HQ', 1_000, true, 14, 48);
$again = new MapClient([
    'tunnel-id>1' => '<response status="success"><result><encap-bytes>6000</encap-bytes><decap-bytes>4500</decap-bytes></result></response>',
    'tunnel-id>2' => '<response status="success"><result><encap-bytes>3000</encap-bytes><decap-bytes>5500</decap-bytes></result></response>',
    '<flow></flow>' => $sharedFlow,
    'ipsec-sa' => '<response status="success"><result><entries></entries></result></response>',
    'statistics' => $emptyGp,
    '<gateway></gateway>' => $emptyGp,
    'current-user' => $emptyGp,
    '<interface>tunnel.3</interface>' => '<response status="success"><result><ibytes>10</ibytes><obytes>20</obytes></result></response>',
]);
$engine->poll($again, $repo, 7, 'hq.example', 'HQ', 1_010, true, 14, 48);
$dash = $repo->dashboard();
$byName = [];
foreach ($dash['tunnels'] as $tunnel) {
    $byName[$tunnel['name']] = $tunnel;
}
check('encap rate', (int) $byName['A']['bps_out'] === 4000, (string) $byName['A']['bps_out']);
check('decap rate', (int) $byName['A']['bps_in'] === 3200, (string) $byName['A']['bps_in']);
check('flat counter is zero', (int) $byName['B']['bps_out'] === 0);
$rates = Presenter::rates($dash['tunnels']);
check('rates sum per-tunnel counters', $rates['out'] === 4000, (string) $rates['out']);

$repo->fail(9, 'edge.example', 'Edge', 'Invalid credential', 1_010);
$afterFail = $repo->dashboard();
check('failed poll keeps tunnels', count($afterFail['tunnels']) === 3);
$edge = null;
foreach ($afterFail['firewalls'] as $firewall) {
    if ((int) $firewall['device_id'] === 9) {
        $edge = $firewall;
    }
}
check('failure recorded', $edge !== null && $edge['last_error'] === 'Invalid credential');

$reset = new Snapshot([[
    'pan_id' => '1', 'name' => 'Reset', 'gateway' => null, 'local_ip' => null, 'peer_ip' => '198.51.100.9',
    'inner_if' => 'tunnel.9', 'outer_if' => null, 'state' => 'active', 'monitor' => 'off', 'monitor_status' => null,
    'protocol' => null, 'encryption' => null, 'auth_hash' => null, 'encap_bytes' => 5000, 'decap_bytes' => 5000,
    'bandwidth_scope' => 'tunnel',
]], [], []);
$repo->save(3, 'branch.example', 'Branch', $reset, 2_000, 5, 14, 48);
$reset->tunnels[0]['encap_bytes'] = 100;
$reset->tunnels[0]['decap_bytes'] = 100;
$repo->save(3, 'branch.example', 'Branch', $reset, 2_010, 5, 14, 48);
$resetRow = null;
foreach ($repo->dashboard()['tunnels'] as $tunnel) {
    if ($tunnel['name'] === 'Reset') {
        $resetRow = $tunnel;
    }
}
check('reset does not invent a rate', $resetRow['bps_out'] === null);

echo "settings and html\n";
$merged = SettingsMerger::merge(
    ['verify_tls' => true, 'timeout' => 20, 'retention_days' => 14, 'per_tunnel_counters' => true, 'devices' => ['4' => ['enabled' => true, 'api_key' => 'secret', 'host' => '', 'port' => 443, 'target' => '']]],
    ['verify_tls' => '0', 'timeout' => 15, 'retention_days' => 7, 'per_tunnel_counters' => '1', 'devices' => ['4' => ['enabled' => '1', 'api_key' => '', 'host' => 'bad host', 'port' => 443, 'target' => '0123ABC'], '5' => ['enabled' => '0', 'api_key' => 'new-key', 'host' => 'fw.example', 'port' => 444, 'target' => '']]]
);
check('blank key is preserved', $merged['devices']['4']['api_key'] === 'secret');
check('bad host dropped', $merged['devices']['4']['host'] === '');
check('new device key saved', $merged['devices']['5']['api_key'] === 'new-key' && $merged['devices']['5']['enabled'] === false);
$cleared = SettingsMerger::merge($merged, ['devices' => ['4' => ['enabled' => '1', 'api_key' => '', 'clear_key' => '1', 'host' => '', 'port' => 443, 'target' => '']]]);
check('clear key removes it', $cleared['devices']['4']['api_key'] === '' && $cleared['devices']['5']['api_key'] === 'new-key');

$vm = Presenter::dashboard($repo->dashboard(), ['fw' => 7, 'state' => 'down', 'q' => ''], '', 'America/Chicago', false, 1_010);
$html = Html::dashboard($vm);
check('down filter keeps the inactive tunnel', str_contains($html, 'class="pv-name">C') && ! str_contains($html, 'class="pv-name">A'));
check('filtered page escapes markup', ! str_contains($html, '<script'));
$nasty = new Snapshot([[
    'pan_id' => '1', 'name' => '<script>alert(1)</script>', 'gateway' => null, 'local_ip' => null, 'peer_ip' => '198.51.100.50',
    'inner_if' => 'tunnel.1', 'outer_if' => null, 'state' => 'active', 'monitor' => 'off', 'monitor_status' => null,
    'protocol' => 'ESP', 'encryption' => 'G256', 'auth_hash' => 'SHA256', 'encap_bytes' => 1, 'decap_bytes' => 1,
    'bandwidth_scope' => 'tunnel',
]], [], []);
$repo->save(8, 'html.example', 'HTML', $nasty, 3_000, 1, 14, 48);
$nasty->tunnels[0]['encap_bytes'] = 101;
$nasty->tunnels[0]['decap_bytes'] = 101;
$repo->save(8, 'html.example', 'HTML', $nasty, 3_010, 1, 14, 48);
$page = Html::dashboard(Presenter::dashboard($repo->dashboard(), [], '/plugin/panos-vpn', 'America/Chicago', false, 3_010));
check('tunnel name is escaped', str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;') && ! str_contains($page, '<script>alert'));
check('crypto label', str_contains($page, 'ESP / AES-GCM-256 / SHA-256'));
$spark = Format::sparkline([100, null, 200], [10, 20, 30]);
check('sparkline gaps on missing samples', substr_count($spark, 'M ') === 3);
check('sparkline uses stroke classes', str_contains($spark, 'pv-spark-in') && str_contains($spark, 'pv-spark-out') && ! str_contains($spark, 'fill="'));

echo "\n{$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
