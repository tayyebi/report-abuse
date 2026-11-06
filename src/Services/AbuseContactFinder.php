<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Derives candidate abuse contacts from enrichment data.
 */
final class AbuseContactFinder
{
    /**
     * All plausible e-mail addresses in a blob of text (WHOIS, RDAP, ...).
     *
     * @return list<string>
     */
    public static function extractEmails(string $text): array
    {
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches) === false) {
            return [];
        }

        $emails = [];
        foreach ($matches[0] as $email) {
            $email = strtolower(trim($email, " \t.,;:<>()[]'\""));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (str_contains($email, '..') || str_ends_with($email, '.invalid')) {
                continue;
            }
            $emails[$email] = true;
        }

        return array_keys($emails);
    }

    /**
     * Addresses that look like abuse desks.
     *
     * @return list<string>
     */
    public static function abuseEmails(string $text): array
    {
        $out = [];
        foreach (self::extractEmails($text) as $email) {
            $local = explode('@', $email)[0];
            if (str_contains($local, 'abuse') || str_contains($local, 'security') || str_contains($local, 'cert')) {
                $out[] = $email;
            }
        }

        return $out;
    }

    /**
     * Abuse contacts declared in an RDAP response (entities with role "abuse").
     *
     * @param array<string, mixed> $rdap
     * @return list<array{email: string, provider: string}>
     */
    public static function fromRdap(array $rdap): array
    {
        $entities = $rdap['entities'] ?? [];
        if (!is_array($entities)) {
            return [];
        }

        $explicit = [];
        $implicit = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $roles = array_map('strtolower', (array) ($entity['roles'] ?? []));
            $provider = self::rdapEntityName($entity);
            $emails = self::rdapEntityEmails($entity);

            if (in_array('abuse', $roles, true) || in_array('abuse-contact', $roles, true)) {
                foreach ($emails as $email) {
                    $explicit[] = ['email' => $email, 'provider' => $provider];
                }
                continue;
            }

            // Some registries only expose abuse addresses on the registrar entity.
            foreach ($emails as $email) {
                if (str_contains(explode('@', $email)[0], 'abuse')) {
                    $implicit[] = ['email' => $email, 'provider' => $provider];
                }
            }
        }

        // Entities that declare an abuse role win over heuristic matches.
        return self::dedupe(array_merge($explicit, $implicit));
    }

    /**
     * @param array<string, mixed> $entity
     */
    public static function rdapEntityName(array $entity): string
    {
        $vcard = $entity['vcardArray'][1] ?? null;
        if (is_array($vcard)) {
            foreach ($vcard as $field) {
                if (is_array($field) && ($field[0] ?? '') === 'fn' && isset($field[3])) {
                    return (string) $field[3];
                }
            }
        }

        return (string) ($entity['handle'] ?? '');
    }

    /**
     * @param array<string, mixed> $entity
     * @return list<string>
     */
    public static function rdapEntityEmails(array $entity): array
    {
        $emails = [];
        $vcard = $entity['vcardArray'][1] ?? null;
        if (is_array($vcard)) {
            foreach ($vcard as $field) {
                if (!is_array($field) || ($field[0] ?? '') !== 'email') {
                    continue;
                }
                $value = $field[3] ?? null;
                if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = strtolower($value);
                }
            }
        }

        if (isset($entity['remarks']) && is_array($entity['remarks'])) {
            foreach ($entity['remarks'] as $remark) {
                foreach (self::abuseEmails(json_encode($remark) ?: '') as $email) {
                    $emails[] = $email;
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * ASN abuse contact lookup via the public Abusix DNS zone.
     */
    public static function abusixLookup(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $reverse = self::reverseIp($ip);
        if ($reverse === null) {
            return null;
        }

        $query = $reverse . '.abuse-contacts.abusix.zone';
        $records = @dns_get_record($query, DNS_TXT);
        if (!is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            $txt = '';
            if (isset($record['entries']) && is_array($record['entries'])) {
                $txt = implode('', $record['entries']);
            } elseif (isset($record['txt'])) {
                $txt = (string) $record['txt'];
            }
            foreach (self::extractEmails($txt) as $email) {
                return $email;
            }
            if (str_contains($txt, '@')) {
                return trim($txt);
            }
        }

        return null;
    }

    public static function reverseIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip)));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if (is_string($packed) && strlen($packed) === 16) {
                // Nibble-wise reverse, as used by DNSBL/Abusix lookups.
                $hex = bin2hex($packed);

                return implode('.', array_reverse(str_split($hex)));
            }
        }

        return null;
    }

    /**
     * Combine every discovery technique.
     *
     * @param array<string, mixed> $enrichment
     * @return list<array{email: string, source: string, provider: string}>
     */
    public static function discover(array $enrichment, ?string $domain = null, ?string $ip = null): array
    {
        $found = [];

        $whois = (string) ($enrichment['whois']['raw'] ?? '');
        foreach (self::abuseEmails($whois) as $email) {
            $found[] = ['email' => $email, 'source' => 'whois', 'provider' => ''];
        }

        foreach ((array) ($enrichment['abuse_emails'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['email'])) {
                $found[] = [
                    'email' => (string) $entry['email'],
                    'source' => (string) ($entry['source'] ?? 'rdap'),
                    'provider' => (string) ($entry['provider'] ?? ''),
                ];
            } elseif (is_string($entry)) {
                $found[] = ['email' => $entry, 'source' => 'rdap', 'provider' => ''];
            }
        }

        if ($ip !== null) {
            $abusix = self::abusixLookup($ip);
            if ($abusix !== null && filter_var($abusix, FILTER_VALIDATE_EMAIL)) {
                $found[] = ['email' => strtolower($abusix), 'source' => 'abusix', 'provider' => ''];
            }
        }

        if ($domain !== null && $domain !== '') {
            // RFC 2142 convention, only proposed when the domain actually
            // publishes mail exchangers.
            $mx = (array) ($enrichment['dns']['mx'] ?? []);
            if ($mx !== []) {
                $found[] = ['email' => 'abuse@' . $domain, 'source' => 'rfc2142', 'provider' => ''];
            }
        }

        $normalised = [];
        foreach ($found as $entry) {
            $email = strtolower(trim($entry['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!isset($normalised[$email])) {
                $normalised[$email] = $entry;
                $normalised[$email]['email'] = $email;
            }
        }

        // Prefer explicitly published abuse mailboxes over the heuristic.
        $priority = ['rdap' => 0, 'whois' => 1, 'abusix' => 2, 'rfc2142' => 3, 'manual' => 4];
        $out = array_values($normalised);
        usort($out, static function (array $a, array $b) use ($priority): int {
            return ($priority[$a['source']] ?? 9) <=> ($priority[$b['source']] ?? 9);
        });

        return $out;
    }

    /**
     * @param list<array{email: string, provider?: string}> $contacts
     * @return list<array{email: string, provider: string}>
     */
    private static function dedupe(array $contacts): array
    {
        $out = [];
        foreach ($contacts as $contact) {
            $email = strtolower($contact['email']);
            if (!isset($out[$email])) {
                $out[$email] = ['email' => $email, 'provider' => (string) ($contact['provider'] ?? '')];
            }
        }

        return array_values($out);
    }
}
