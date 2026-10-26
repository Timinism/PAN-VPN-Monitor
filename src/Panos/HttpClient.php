<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Panos;

final class HttpClient implements Client
{
    public function __construct(
        private readonly string $host,
        private readonly string $apiKey,
        private readonly int $port = 443,
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 20,
        private readonly ?string $target = null,
    ) {
    }

    public function op(string $command): string
    {
        $host = trim($this->host);
        if ($host === '' || strpbrk($host, " \t/?#@") !== false) {
            throw new PanosException('Firewall host is empty or not a hostname');
        }
        if ($this->apiKey === '') {
            throw new PanosException('Firewall API key is empty');
        }

        $endpoint = 'https://'.$host.':'.$this->port.'/api/';
        $fields = [
            'type' => 'op',
            'cmd' => $command,
            'key' => $this->apiKey,
        ];
        if ($this->target !== null && $this->target !== '') {
            $fields['target'] = $this->target;
        }

        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new PanosException('Could not start the PAN-OS request');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => ['Accept: application/xml'],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status >= 400 || $status === 0) {
            $detail = $error !== '' ? $error : 'HTTP '.$status;
            throw new PanosException('PAN-OS API request to '.$host.' failed ('.$detail.')');
        }

        $xml = (string) $body;
        Parser::assertOk($xml);

        return $xml;
    }
}
