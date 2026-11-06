<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;

/**
 * Lazy technical enrichment: DNS, WHOIS, RDAP and a guarded HTTP probe.
 *
 * Every lookup is best-effort and time-boxed; failures are recorded in the
 * "errors" bucket instead of breaking the request.
 */
final class EnrichmentService
{
    private const TIMEOUT = 5;

    /**
     * @return array<string, mixed>
     */
    public function enrich(Ticket $ticket, bool $force = false): array
    {
        $domain = $ticket->domain();
        $ip = $ticket->ip();
        $url = $ticket->url();

        $data = [
            'generated_at' => date('c'),
            'target' => $ticket->target(),
            'domain' => $domain,
            'ip' => $ip,
            'url' => $url,
            'dns' => [],
            'whois' => [],
            'rdap_domain' => [],
            'rdap_ip' => [],
            'http' => [],
            'abuse_emails' => [],
            'errors' => [],
        ];

        if ($domain !== null) {
            try {
                $data['dns'] = $this->dnsLookup($domain);
            } catch (\Throwable $e) {
                $data['errors'][] = 'dns: ' . $e->getMessage();
            }

            try {
                $data['whois'] = $this->whoisLookup($domain);
            } catch (\Throwable $e) {
                $data['errors'][] = 'whois: ' . $e->getMessage();
            }

            try {
                $rdap = $this->rdapDomain($domain);
                if ($rdap !== null) {
                    $data['rdap_domain'] = $rdap;
                    foreach (AbuseContactFinder::fromRdap($rdap['raw'] ?? []) as $contact) {
                        $data['abuse_emails'][] = [
                            'email' => $contact['email'],
                            'source' => 'rdap',
                            'provider' => $contact['provider'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $data['errors'][] = 'rdap_domain: ' . $e->getMessage();
            }
        }

        if ($ip !== null) {
            try {
                $rdapIp = $this->rdapIp($ip);
                if ($rdapIp !== null) {
                    $data['rdap_ip'] = $rdapIp;
                    foreach (AbuseContactFinder::fromRdap($rdapIp['raw'] ?? []) as $contact) {
                        $data['abuse_emails'][] = [
                            'email' => $contact['email'],
                            'source' => 'rdap',
                            'provider' => $contact['provider'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $data['errors'][] = 'rdap_ip: ' . $e->getMessage();
            }
        }

        if ($url !== null) {
            try {
                $probe = $this->httpProbe($url);
                if ($probe !== null) {
                    $data['http'] = $probe;
                }
            } catch (\Throwable $e) {
                $data['errors'][] = 'http: ' . $e->getMessage();
            }
        }

        if ($force) {
            $data['forced'] = true;
        }

        return $data;
    }

    /*
     * ---------------------------------------------------------------------
     * DNS
     * ---------------------------------------------------------------------
     */

    /**
     * @return array<string, mixed>
     */
    public function dnsLookup(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if (!preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $domain)) {
            return [];
        }

        $records = @dns_get_record($domain, DNS_A | DNS_AAAA | DNS_MX | DNS_NS | DNS_TXT | DNS_CNAME);
        if (!is_array($records)) {
            $records = [];
        }

        $out = ['a' => [], 'aaaa' => [], 'mx' => [], 'ns' => [], 'txt' => [], 'cname' => []];

        foreach ($records as $record) {
            $type = strtolower((string) ($record['type'] ?? ''));
            switch ($type) {
                case 'a':
                    if (isset($record['ip'])) {
                        $out['a'][] = (string) $record['ip'];
                    }
                    break;
                case 'aaaa':
                    if (isset($record['ipv6'])) {
                        $out['aaaa'][] = (string) $record['ipv6'];
                    }
                    break;
                case 'mx':
                    if (isset($record['target'])) {
                        $out['mx'][] = trim(((int) ($record['prio'] ?? 0)) . ' ' . (string) $record['target']);
                    }
                    break;
                case 'ns':
                    if (isset($record['target'])) {
                        $out['ns'][] = (string) $record['target'];
                    }
                    break;
                case 'cname':
                    if (isset($record['target'])) {
                        $out['cname'][] = (string) $record['target'];
                    }
                    break;
                case 'txt':
                    $txt = $record['txt'] ?? $record['entries'] ?? '';
                    if (is_array($txt)) {
                        $txt = implode('', $txt);
                    }
                    $out['txt'][] = str_limit((string) $txt, 300);
                    break;
            }
        }

        return $out;
    }

    /*
     * ---------------------------------------------------------------------
     * WHOIS
     * ---------------------------------------------------------------------
     */

    /**
     * @return array<string, mixed>
     */
    public function whoisLookup(string $domain): array
    {
        $reason = $this->whoisUnavailableReason();
        if ($reason !== null) {
            return ['available' => false, 'reason' => $reason, 'raw' => ''];
        }

        $command = 'timeout 10 whois ' . escapeshellarg($domain) . ' 2>/dev/null';
        $raw = @shell_exec($command);
        if (!is_string($raw) || trim($raw) === '') {
            return ['available' => true, 'found' => false, 'raw' => ''];
        }

        $raw = str_limit($raw, 20000, "\n[truncated]\n");

        return [
            'available' => true,
            'found' => true,
            'raw' => $raw,
            'abuse_emails' => AbuseContactFinder::abuseEmails($raw),
            'registrar' => $this->extractRegistrar($raw),
            'created' => $this->extractField($raw, ['Creation Date', 'created', 'Registered on']),
            'expires' => $this->extractField($raw, ['Registry Expiry Date', 'Expiry Date', 'expires']),
        ];
    }

    public function whoisUnavailableReason(): ?string
    {
        if (!function_exists('shell_exec')) {
            return 'shell_exec() is disabled on this server';
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) {
            return 'shell_exec() is disabled on this server';
        }

        $which = @shell_exec('command -v whois 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return 'the whois binary is not installed';
        }

        return null;
    }

    private function extractRegistrar(string $raw): ?string
    {
        return $this->extractField($raw, ['Registrar:', 'registrar:', 'Sponsoring Registrar']);
    }

    /**
     * @param list<string> $keys
     */
    private function extractField(string $raw, array $keys): ?string
    {
        foreach ($keys as $key) {
            $pattern = '/^\s*' . preg_quote($key, '/') . ':\s*(.+)$/mi';
            if (preg_match($pattern, $raw, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /*
     * ---------------------------------------------------------------------
     * RDAP
     * ---------------------------------------------------------------------
     */

    /**
     * @return array<string, mixed>|null
     */
    public function rdapDomain(string $domain): ?array
    {
        $json = $this->httpGetJson('https://rdap.org/domain/' . rawurlencode($domain));
        if ($json === null) {
            return null;
        }

        $status = $json['status'] ?? [];
        $events = [];
        foreach ((array) ($json['events'] ?? []) as $event) {
            if (is_array($event) && isset($event['eventAction'], $event['eventDate'])) {
                $events[(string) $event['eventAction']] = (string) $event['eventDate'];
            }
        }

        $registrar = null;
        foreach ((array) ($json['entities'] ?? []) as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $roles = array_map('strtolower', (array) ($entity['roles'] ?? []));
            if (in_array('registrar', $roles, true)) {
                $registrar = AbuseContactFinder::rdapEntityName($entity);
                break;
            }
        }

        $nameServers = [];
        foreach ((array) ($json['nameservers'] ?? []) as $ns) {
            if (is_array($ns) && isset($ns['ldhName'])) {
                $nameServers[] = (string) $ns['ldhName'];
            }
        }

        return [
            'handle' => $json['handle'] ?? null,
            'registrar' => $registrar,
            'status' => is_array($status) ? array_values(array_map('strval', $status)) : [],
            'events' => $events,
            'name_servers' => $nameServers,
            'raw' => $json,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rdapIp(string $ip): ?array
    {
        $json = $this->httpGetJson('https://rdap.org/ip/' . rawurlencode($ip));
        if ($json === null) {
            return null;
        }

        $start = $json['startAddress'] ?? null;
        $end = $json['endAddress'] ?? null;

        return [
            'network' => $json['name'] ?? $json['handle'] ?? null,
            'country' => strtoupper((string) ($json['country'] ?? '')),
            'range' => $start !== null && $end !== null ? $start . ' - ' . $end : null,
            'type' => $json['type'] ?? null,
            'raw' => $json,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpGetJson(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/rdap+json, application/json\r\nUser-Agent: AbuseReportDesk/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || trim($body) === '') {
            return null;
        }
        if (strlen($body) > 4_000_000) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /*
     * ---------------------------------------------------------------------
     * HTTP probe
     * ---------------------------------------------------------------------
     */

    /**
     * Fetch the first bytes of the reported URL to record status and title.
     *
     * @return array<string, mixed>|null
     */
    public function httpProbe(string $url): ?array
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443, 8080, 8443], true)) {
            return null;
        }

        if (!$this->isPublicHost((string) $parts['host'])) {
            return ['skipped' => 'target resolves to a private or reserved address'];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => 4,
                'ignore_errors' => true,
                'header' => "User-Agent: AbuseReportDesk/1.0 (+abuse triage)\r\nAccept: */*\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return ['url' => $url, 'error' => 'request failed'];
        }

        $meta = stream_get_meta_data($handle);
        $headers = $meta['wrapper_data'] ?? [];
        $snippet = (string) fread($handle, 16384);
        fclose($handle);

        $status = null;
        $finalUrl = $url;
        $server = null;
        $contentType = null;
        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $m)) {
                    $status = (int) $m[1];
                } elseif (stripos((string) $header, 'location:') === 0) {
                    $finalUrl = trim(substr((string) $header, 9));
                } elseif (stripos((string) $header, 'server:') === 0) {
                    $server = trim(substr((string) $header, 7));
                } elseif (stripos((string) $header, 'content-type:') === 0) {
                    $contentType = trim(substr((string) $header, 13));
                }
            }
        }

        $title = null;
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $snippet, $m)) {
            $title = str_limit(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]))) ?? ''), 200);
        }

        return [
            'url' => $url,
            'final_url' => $finalUrl,
            'status' => $status,
            'server' => $server,
            'content_type' => $contentType,
            'title' => $title,
        ];
    }

    private function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $addresses = @gethostbynamel($host);
        if (!is_array($addresses) || $addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
