# Palo Alto VPN for LibreNMS

LibreNMS plugin that polls PAN-OS firewalls for site-to-site VPN tunnels and GlobalProtect sessions.

The plugin page shows:

- How many site-to-site tunnels are up, down, or still initializing
- Throughput per tunnel, with decap as **in** and encap as **out**
- GlobalProtect gateways and the users connected to each one

The same summary is added to the overview of each PAN-OS device.

## Lab

A simulated estate is included so the pages can be opened without a firewall or a LibreNMS install. HQ-FW1, DC-FW1, and AUS-Branch answer immediately. Edge-Lab has no API key until you add one on the settings page. Any non-empty key is accepted in the lab.

```bash
php -S 127.0.0.1:8741 demo/server.php
```

Open http://127.0.0.1:8741

Counters move about every five seconds. Austin is site-to-site only, so its GlobalProtect section stays empty. HQ-to-Plant and AUS-to-DC stay down.

```bash
php tests/run.php
```

## Install in LibreNMS

Copy this directory onto the LibreNMS host, then add it as a path repository:

```bash
cd /opt/librenms
composer config repositories.panos-vpn '{"type":"path","url":"/opt/librenms-plugins/panos-vpn","symlink":true}'
lnms plugin:add librenms-plugin/panos-vpn
php artisan migrate
```

The plugin registers itself as `panos-vpn` and is enabled on install. Open **Plugins → Palo Alto VPN**. API keys are edited from **Plugin Admin → Palo Alto VPN → Settings**, which is also at `/plugin/settings/panos-vpn`.

Polling runs at the end of the normal LibreNMS poll for every device whose OS is `panos` and that has an API key saved. To poll on a timer instead, or as well, add:

```cron
*/5 * * * * php /opt/librenms/artisan panos-vpn:poll >> /dev/null 2>&1
```

`php artisan panos-vpn:poll hq-fw.example` polls one firewall. A second poll inside the same minute is skipped.

## API access

Create a PAN-OS admin role that allows the XML API and operational requests, and that cannot commit configuration. Generate a key from an admin shell on a trusted host:

```text
https://firewall.example/api/?type=keygen&user=librenms-vpn&password=REPLACE
```

Paste the key into the plugin settings. Leave the host blank to use the hostname LibreNMS already has. Set **Panorama serial** only when the request must go through Panorama to a specific firewall.

The poller sends only these operational commands:

- `show vpn flow`
- `show vpn ipsec-sa`
- `show vpn flow tunnel-id <id>` when several proxy-IDs share one tunnel interface
- `show interface <tunnel.x>` when the flow table has no byte counters
- `show global-protect-gateway statistics`
- `show global-protect-gateway gateway`
- `show global-protect-gateway current-user`

In is decapsulated traffic arriving from the peer. Out is encapsulated traffic sent to the peer. A counter reset, such as a rekey, is skipped for that interval so it does not appear as a traffic spike. When a rate is marked **shared iface**, it is the tunnel interface total because the firewall did not return per-proxy-ID counters.

History is kept for 14 days by default. Failed polls keep the last good snapshot and show the API error on the firewall.
