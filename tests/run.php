<?php

declare(strict_types=1);

/**
 * Dependency-free unit test suite (no database required).
 *
 *   php tests/run.php
 *
 * For the full HTTP + PostgreSQL integration suite see tests/integration.php.
 */

use App\Services\AbuseContactFinder;
use App\Services\ArfFormatter;
use App\Services\EmailParser;
use App\Services\MimeBuilder;
use App\Services\ReportData;
use App\Services\Templates;
use App\Services\TicketService;
use App\Services\XarfFormatter;

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

/* -------------------------------------------------------------------------
 * Target normalisation
 * ---------------------------------------------------------------------- */
T::suite('Target normalisation');

$cases = [
    ['reported_url' => 'https://evil.example/login?id=1', 'domain' => 'evil.example', 'url' => 'https://evil.example/login?id=1'],
    ['target' => 'evil.example', 'domain' => 'evil.example', 'url' => 'http://evil.example'],
    ['target' => 'https://203.0.113.9/pay', 'domain' => null, 'ip' => '203.0.113.9', 'url' => 'https://203.0.113.9/pay'],
    ['reported_ip' => '198.51.100.7', 'domain' => null, 'ip' => '198.51.100.7'],
    ['reported_domain' => 'Bad-Example.COM', 'domain' => 'bad-example.com', 'url' => 'http://bad-example.com'],
];

foreach ($cases as $index => $case) {
    $input = array_intersect_key($case, array_flip(['target', 'reported_url', 'reported_domain', 'reported_ip']));
    $result = TicketService::normaliseTarget($input);

    T::same(null, $result['error'], 'case ' . $index . ': accepted');
    T::same($case['domain'], $result['domain'], 'case ' . $index . ': domain');
    T::same($case['url'] ?? null, $result['url'], 'case ' . $index . ': url');
    T::same($case['ip'] ?? null, $result['ip'], 'case ' . $index . ': ip');
}

T::same('http://example.com/a', TicketService::normaliseTarget(['target' => 'example.com/a'])['url'], 'domain + path becomes URL');
T::same('sub.example.co.uk', TicketService::normaliseTarget(['target' => 'sub.example.co.uk'])['domain'], 'multi-label domain parsed');
T::ok(TicketService::isValidAbuseType('phishing'), 'phishing is a valid abuse type');
T::ok(!TicketService::isValidAbuseType('nonsense'), 'unknown abuse type rejected');

/* -------------------------------------------------------------------------
 * MIME helpers
 * ---------------------------------------------------------------------- */
T::suite('MIME helpers');

T::same("a\r\nb\r\nc", MimeBuilder::normalize("a\nb\rc"), 'line endings normalised to CRLF');
T::same("..leading dot\r\nnormal", MimeBuilder::dotStuff(".leading dot\r\nnormal"), 'dot stuffing for SMTP DATA');
T::same('plain subject', MimeBuilder::encodeHeader('plain subject'), 'ASCII header untouched');
T::ok(str_starts_with(MimeBuilder::encodeHeader('Ünïcode'), '=?UTF-8?B?'), 'non-ASCII header is RFC 2047 encoded');
T::same('"Jane Doe" <jane@example.org>', MimeBuilder::address('jane@example.org', 'Jane Doe'), 'display name quoting');
T::ok((bool) preg_match('/^<report\.\d{14}\.[a-f0-9]{16}@example\.org>$/', MimeBuilder::messageId('example.org', 'report')), 'Message-ID format');
T::same('badname.bin', MimeBuilder::sanitizeFilename("bad\"name\n.bin"), 'quotes and newlines stripped from filenames');
T::ok(!str_contains(MimeBuilder::sanitizeFilename("x\r\ny.txt"), "\n"), 'filename cannot break headers');

/* -------------------------------------------------------------------------
 * E-mail parsing
 * ---------------------------------------------------------------------- */
T::suite('E-mail parsing');

$rawEmail = implode("\r\n", [
    'From: "Phish Watch" <reporter@example.org>',
    'To: abuse@our-desk.example',
    'Subject: =?UTF-8?B?UmVwb3J0IMOkYmVy?=',
    'Date: Tue, 06 Oct 2026 10:00:00 +0000',
    'Message-ID: <abc123@example.org>',
    'In-Reply-To: <report.20260101.deadbeef@abuse-desk.local>',
    'References: <other@example.org> <report.20260101.deadbeef@abuse-desk.local>',
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="outer"',
    '',
    '--outer',
    'Content-Type: text/plain; charset=utf-8',
    'Content-Transfer-Encoding: quoted-printable',
    '',
    'Hello, look at https://evil.example/login and this =C3=A9 text.',
    '',
    '--outer',
    'Content-Type: application/octet-stream; name="evidence.png"',
    'Content-Disposition: attachment; filename="evidence.png"',
    'Content-Transfer-Encoding: base64',
    '',
    base64_encode('PNG-FAKE-DATA'),
    '--outer--',
    '',
]);

$parsed = EmailParser::parse($rawEmail);

T::same('reporter@example.org', $parsed['from'], 'from address extracted');
T::same('Phish Watch', $parsed['from_name'], 'display name extracted');
T::same('abuse@our-desk.example', $parsed['to'], 'to address extracted');
T::same('Report äber', $parsed['subject'], 'RFC 2047 subject decoded');
T::same('2026-10-06 10:00:00', $parsed['date'], 'date normalised');
T::same('abc123@example.org', $parsed['message_id'], 'Message-ID de-bracketed');
T::same('report.20260101.deadbeef@abuse-desk.local', $parsed['in_reply_to'], 'In-Reply-To captured');
T::ok(in_array('report.20260101.deadbeef@abuse-desk.local', $parsed['references'], true), 'References captured');
T::contains('https://evil.example/login', $parsed['body_text'], 'quoted-printable body decoded');
T::contains('é text', $parsed['body_text'], 'UTF-8 body preserved');
T::same(1, count($parsed['attachments']), 'one attachment parsed');
T::same('evidence.png', $parsed['attachments'][0]['filename'], 'attachment filename');
T::same('PNG-FAKE-DATA', $parsed['attachments'][0]['data'], 'base64 attachment decoded');

$guessed = (new TicketService());
T::same('https://evil.example/login', $guessed->guessTarget('See https://evil.example/login.'), 'URL guessed from message');
T::same('198.51.100.9', $guessed->guessTarget('Please block 198.51.100.9 now'), 'IP guessed from message');
T::same('phishing', $guessed->guessAbuseType('This is a phishing page stealing credentials'), 'abuse type guessed');
T::same('other', $guessed->guessAbuseType('something odd'), 'abuse type falls back to other');

/* -------------------------------------------------------------------------
 * Abuse contact discovery
 * ---------------------------------------------------------------------- */
T::suite('Abuse contact discovery');

$whois = "Registrar WHOIS Server: whois.example\nOrgAbuseEmail: Abuse@Example-Registrar.NET\nabuse-mailbox: report@abuse.example.org\n";
$emails = AbuseContactFinder::extractEmails($whois);
T::ok(in_array('abuse@example-registrar.net', $emails, true), 'e-mails extracted (lower-cased)');
T::ok(in_array('abuse@example-registrar.net', AbuseContactFinder::abuseEmails($whois), true), 'abuse mailbox identified');
T::ok(!in_array('report@abuse.example.org', AbuseContactFinder::abuseEmails($whois), true), 'non-abuse local parts are not treated as abuse desks');

$rdap = [
    'entities' => [
        [
            'handle' => 'REG-1',
            'roles' => ['registrar'],
            'vcardArray' => ['vcard', [['fn', [], 'text', 'Example Registrar Inc.'], ['email', [], 'text', 'abuse@registrar.example']]],
        ],
        [
            'roles' => ['abuse'],
            'vcardArray' => ['vcard', [['fn', [], 'text', 'Network Abuse Desk'], ['email', [], 'text', 'network-abuse@host.example']]],
        ],
    ],
];
$rdapContacts = AbuseContactFinder::fromRdap($rdap);
$rdapEmails = array_column($rdapContacts, 'email');
T::ok(in_array('network-abuse@host.example', $rdapEmails, true), 'RDAP abuse role extracted');
T::ok(in_array('abuse@registrar.example', $rdapEmails, true), 'RDAP registrar abuse address extracted');
T::same('Network Abuse Desk', $rdapContacts[0]['provider'] ?? '', 'RDAP provider name extracted');

T::same('9.100.51.198', AbuseContactFinder::reverseIp('198.51.100.9'), 'IPv4 reverse for Abusix lookup');
$ipv6Reverse = AbuseContactFinder::reverseIp('2001:db8::1');
T::same(32, $ipv6Reverse === null ? 0 : count(explode('.', $ipv6Reverse)), 'IPv6 reverse has 32 nibbles');
T::same(null, AbuseContactFinder::reverseIp('not-an-ip'), 'invalid IP rejected');

$discovered = AbuseContactFinder::discover([
    'whois' => ['raw' => $whois],
    'dns' => ['mx' => ['10 mx.example.org']],
    'abuse_emails' => [['email' => 'network-abuse@host.example', 'source' => 'rdap', 'provider' => 'Host']],
], 'example.org', null);

$discoveredEmails = array_column($discovered, 'email');
T::ok(in_array('network-abuse@host.example', $discoveredEmails, true), 'RDAP contact in discovery result');
T::ok(in_array('abuse@example.org', $discoveredEmails, true), 'RFC 2142 fallback proposed when MX exists');
T::same('rdap', $discovered[0]['source'] ?? '', 'RDAP contacts ranked first');

/* -------------------------------------------------------------------------
 * ARF / X-ARF formatting
 * ---------------------------------------------------------------------- */
T::suite('ARF / X-ARF formatting');

$data = new ReportData(
    ticketNumber: 'ABR-20261106-0001',
    abuseType: 'phishing',
    abuseTypeLabel: 'Phishing / credential theft',
    reportedUrl: 'https://evil.example/login',
    reportedDomain: 'evil.example',
    reportedIp: '203.0.113.9',
    description: "Fake login page harvesting credentials.\nSeen at 10:00 UTC.",
    reporterEmail: 'reporter@example.org',
    reporterName: 'Jane Doe',
    reporterOrg: 'Abuse Report Desk',
    reporterOrgDomain: 'abuse-desk.local',
    reporterFromEmail: 'abuse@abuse-desk.local',
    recipient: 'abuse@host.example',
    subject: '[ABR-20261106-0001] Phishing report for evil.example',
    messageId: '<report.20261106100000.abcdef@abuse-desk.local>',
    dateHeader: 'Tue, 06 Oct 2026 10:00:00 +0000',
    ticketUrl: 'https://desk.example/ticket/tok_123',
    evidence: [['filename' => 'shot.png', 'content_type' => 'image/png', 'data' => 'PNGDATA']],
    enrichment: ['dns' => ['a' => ['203.0.113.9'], 'mx' => ['10 mx.host.example']]]
);

$arf = new ArfFormatter();
T::same('phishing', $arf->feedbackType('phishing'), 'phishing maps to RFC 5965 phishing');
T::same('virus', $arf->feedbackType('malware'), 'malware maps to RFC 5965 virus');
T::same('other', $arf->feedbackType('unknown'), 'unknown type falls back to other');

$message = $arf->format($data);
T::contains('Content-Type: multipart/report; report-type="feedback-report"', $message, 'ARF content type');
T::contains('Auto-Submitted: auto-generated', $message, 'ARF auto-submitted header');
T::contains('X-Abuse-Ticket: ABR-20261106-0001', $message, 'ticket header present');
T::contains('Content-Type: message/feedback-report', $message, 'feedback report part');
T::contains('Feedback-Type: phishing', $message, 'feedback type field');
T::contains('Reported-URI: https://evil.example/login', $message, 'reported URI field');
T::contains('Reported-Domain: evil.example', $message, 'reported domain field');
T::contains('Source-IP: 203.0.113.9', $message, 'source IP field');
T::contains('To: abuse@host.example', $message, 'recipient header');
T::contains('shot.png', $message, 'evidence filename referenced');
T::contains('A records: 203.0.113.9', $message, 'DNS enrichment included');
T::notContains('{{', $message, 'no unrendered template placeholders');
T::contains('SHA-256', $message, 'evidence digests included');

$human = $arf->plainHumanText($data);
T::contains('ABR-20261106-0001', $human, 'human part carries ticket number');
T::contains('Fake login page harvesting credentials.', $human, 'human part carries description');
T::contains('abuse@abuse-desk.local', $human, 'human part carries reply address');

$edited = $arf->format($data, 'Custom analyst message only.');
T::contains('Custom analyst message only.', $edited, 'human part override respected');
$humanPart = substr($edited, (int) strpos($edited, '--'), (int) strpos($edited, 'message/feedback-report') - (int) strpos($edited, '--'));
T::notContains('Fake login page harvesting credentials.', $humanPart, 'override replaces the template body');

$xarf = new XarfFormatter();
$document = $xarf->buildReport($data);
T::same('3', $document['Version'], 'X-ARF version 3');
T::same('Phishing', $document['ReportType'], 'X-ARF report type mapping');
T::same('ABR-20261106-0001', $document['Report']['TicketId'], 'X-ARF ticket id');
T::same('reporter@example.org', $document['ReporterInfo']['ReporterContactEmail'], 'X-ARF reporter contact');
T::same(1, count($document['Attachment']), 'X-ARF attachment included');
T::same('shot.png', $document['Attachment'][0]['FileName'], 'X-ARF attachment filename');
T::same(hash('sha256', 'PNGDATA'), $document['Attachment'][0]['Hash'], 'X-ARF attachment hash');

$xarfMessage = $xarf->format($data);
T::contains('application/json', $xarfMessage, 'X-ARF MIME part type');
T::contains('"ReportType": "Phishing"', $xarfMessage, 'X-ARF JSON embedded in MIME');

/* -------------------------------------------------------------------------
 * Templates and helpers
 * ---------------------------------------------------------------------- */
T::suite('Templates and helpers');

$rendered = Templates::render('arf_generic', [
    'ticket_number' => 'ABR-1',
    'abuse_type_label' => 'Phishing',
    'feedback_type' => 'phishing',
    'target' => 'evil.example',
    'url' => 'https://evil.example',
    'domain' => 'evil.example',
    'ip' => '203.0.113.9',
    'date' => 'now',
    'description' => 'desc',
    'enrichment' => 'dns',
    'evidence_list' => 'none',
    'org' => 'Desk',
    'org_email' => 'abuse@desk.example',
    'ticket_url' => 'https://desk.example/t',
]);
T::contains('ABR-1', $rendered, 'ticket number substituted');
T::notContains('{{', $rendered, 'no placeholders left in template output');
T::ok(Templates::exists('reporter_confirmation'), 'reporter confirmation template exists');
T::same('', Templates::render('does_not_exist'), 'missing template returns empty string');

T::same('badge-reported', status_class('reported'), 'status badge class');
T::same('badge-other', status_class(''), 'empty status falls back');
T::same('1 KB', format_bytes(1024), 'byte formatting');
T::same('1.5 MB', format_bytes(1572864), 'megabyte formatting');
T::same('just now', time_ago(date('Y-m-d H:i:s')), 'time_ago for now');
T::same('never', time_ago(null), 'time_ago for null');
T::same('🎣', abuse_icon('phishing'), 'abuse icon mapping');
T::same('&lt;b&gt;', e('<b>'), 'HTML escaping');

exit(T::summary());
