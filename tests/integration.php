<?php

declare(strict_types=1);

/**
 * End-to-end suite: schema, models, services and full HTTP request cycles.
 *
 *   php tests/integration.php
 *
 * WARNING: it drops and recreates the tables in the configured database, so
 * point it at a disposable database (e.g. DB_NAME=abuse_desk_test).
 *
 * Optional injection for embedded environments:
 *   TEST_DSN=pgsql: TEST_DB_USER= TEST_DB_PASSWORD=
 */

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\AbuseContact;
use App\Models\Admin;
use App\Models\Evidence;
use App\Models\InboundMessage;
use App\Models\LoginCode;
use App\Models\OutboundReport;
use App\Models\SystemState;
use App\Models\Ticket;
use App\Services\AuthService;
use App\Services\EvidenceService;
use App\Services\MailboxProcessor;
use App\Services\TicketService;

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

// Deterministic test environment.
Config::set('app.debug', true);
Config::set('app.url', 'http://localhost:8080');
Config::set('app.timezone', 'UTC');
Config::set('mail.transport', 'log');
Config::set('mail.smtp.host', '');
Config::set('mail.imap.host', '');
Config::set('app.uploads.path', storage_path('uploads'));
Config::set('app.piggyback.imap_interval', 300);

$dsn = (string) env('TEST_DSN', '');
if ($dsn !== '') {
    $pdo = new PDO($dsn, (string) env('TEST_DB_USER', ''), (string) env('TEST_DB_PASSWORD', ''));
    Database::usePdo($pdo);
}

$db = Database::instance();
$db->pdo();

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Dispatch a fake HTTP request through the real front controller.
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $files
 */
function req(string $method, string $path, array $post = [], array $files = [], bool $csrf = true): Response
{
    $parts = parse_url($path) ?: [];
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['HTTP_USER_AGENT'] = 'integration-test';
    unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTPS']);

    $_GET = [];
    parse_str((string) ($parts['query'] ?? ''), $_GET);
    $_POST = $post;
    if ($method === 'POST' && $csrf && !isset($_POST['_csrf'])) {
        $_POST['_csrf'] = Session::csrfToken();
    }
    $_FILES = $files;

    return (new App())->handle(Request::capture());
}

function location(Response $response): string
{
    return (string) ($response->headerValue('Location') ?? '');
}

function outboxCount(?string $directory = null): int
{
    $directory = $directory ?? storage_path('outbox');
    $files = glob($directory . '/*.eml') ?: [];

    return count($files);
}

function outboxContents(): string
{
    $out = '';
    foreach (glob(storage_path('outbox') . '/*.eml') ?: [] as $file) {
        $out .= (string) file_get_contents($file);
    }

    return $out;
}

/* -------------------------------------------------------------------------
 * Schema
 * ---------------------------------------------------------------------- */
T::suite('Schema');

$db->pdo()->exec('DROP TABLE IF EXISTS admin_login_codes, ticket_events, inbound_messages, outbound_reports, abuse_contacts, evidence, tickets, admins, system_state CASCADE');
$schema = (string) file_get_contents(base_path('database/schema.sql'));
$db->pdo()->exec($schema);

$tables = $db->fetchAll("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
$tableNames = array_map(static fn (array $row): string => (string) $row['table_name'], $tables);
foreach (['admins', 'admin_login_codes', 'tickets', 'evidence', 'abuse_contacts', 'outbound_reports', 'inbound_messages', 'ticket_events', 'system_state'] as $expected) {
    T::ok(in_array($expected, $tableNames, true), 'table exists: ' . $expected);
}

$adminColumns = array_map(
    static fn (array $row): string => (string) $row['column_name'],
    $db->fetchAll("SELECT column_name FROM information_schema.columns WHERE table_name = 'admins'")
);
T::ok(in_array('email', $adminColumns, true), 'admins stores an e-mail address');
T::ok(!in_array('password_hash', $adminColumns, true), 'admins has no password column');
T::ok(!in_array('password', $adminColumns, true), 'admins has no password field at all');
T::ok(!in_array('username', $adminColumns, true), 'admins has no username column');
T::ok(!in_array('totp_secret', $adminColumns, true), 'admins has no shared secret column');

// Re-running the schema must be safe.
try {
    $db->pdo()->exec($schema);
    T::ok(true, 'schema is idempotent');
} catch (Throwable $e) {
    T::ok(false, 'schema is idempotent (' . $e->getMessage() . ')');
}

T::same('2000-01-01 00:00:00', SystemState::get('last_intake_check'), 'system_state seeded');
T::ok(!SystemState::isFresh('last_intake_check', 60), 'stale state detected');

/* -------------------------------------------------------------------------
 * Administrators & passwordless auth (model level)
 * ---------------------------------------------------------------------- */
T::suite('Administrators & one-time codes');

$seeded = Admin::seedDefault('tayyebimohammadreza@gmail.com');
T::ok($seeded !== null, 'bootstrap administrator seeded');
T::same('tayyebimohammadreza@gmail.com', $seeded?->email(), 'seed e-mail stored exactly as configured');
T::same($seeded?->id(), Admin::seedDefault('TAYYEBIMOHAMMADREZA@gmail.com')?->id(), 'seeding is idempotent and case-insensitive');
T::ok($seeded?->isActive() ?? false, 'seeded administrator is active');

$issued = LoginCode::issue((int) $seeded?->id(), '203.0.113.50');
T::same(6, strlen($issued['code']), 'code has the configured length');
T::ok(LoginCode::verify((int) $seeded?->id(), $issued['code']), 'correct code verifies');
T::ok(!LoginCode::verify((int) $seeded?->id(), $issued['code']), 'code cannot be reused');

$issued2 = LoginCode::issue((int) $seeded?->id());
$wrong = substr($issued2['code'], 0, 5) . ((((int) substr($issued2['code'], 5)) + 1) % 10);
T::ok(!LoginCode::verify((int) $seeded?->id(), $wrong), 'wrong code rejected');
T::ok(LoginCode::verify((int) $seeded?->id(), $issued2['code']), 'correct code still accepted after one failed attempt');

$db->execute(
    'INSERT INTO admin_login_codes (admin_id, code_hash, expires_at) VALUES (:id, :hash, NOW() - INTERVAL \'1 minute\')',
    ['id' => $seeded?->id(), 'hash' => password_hash('123456', PASSWORD_DEFAULT)]
);
T::ok(!LoginCode::verify((int) $seeded?->id(), '123456'), 'expired code rejected');
T::ok(LoginCode::latestFor((int) $seeded?->id()) === null, 'no valid code remains');

$auth = new AuthService();
$unknown = $auth->requestCode('nobody@example.org', '203.0.113.50');
T::ok($unknown['ok'] && $unknown['debug_code'] === null, 'unknown address gets a neutral response (no enumeration)');

$seeded?->setActive(false);
$inactive = $auth->requestCode('tayyebimohammadreza@gmail.com', '203.0.113.50');
T::ok($inactive['ok'] && $inactive['debug_code'] === null, 'disabled administrator receives no code');
$seeded?->setActive(true);

$db->execute('DELETE FROM admin_login_codes');
Config::set('app.auth.request_max', 1);
$first = $auth->requestCode('tayyebimohammadreza@gmail.com', '203.0.113.50');
$second = $auth->requestCode('tayyebimohammadreza@gmail.com', '203.0.113.50');
T::ok($first['ok'], 'first code request accepted');
T::ok(!$second['ok'], 'second request inside the window is rate limited');
Config::set('app.auth.request_max', 5);

/* -------------------------------------------------------------------------
 * Public intake over HTTP
 * ---------------------------------------------------------------------- */
T::suite('Public intake (HTTP)');

$home = req('GET', '/');
T::same(200, $home->statusCode(), 'report form responds 200');
T::contains('Report abuse', $home->content(), 'form renders');
T::contains('name="_csrf"', $home->content(), 'form has a CSRF token');
T::contains('name="abuse_type"', $home->content(), 'form has the abuse type field');

$before = Ticket::countAll();

$response = req('POST', '/report', [
    'abuse_type' => 'phishing',
    'target' => 'https://evil.example/login?x=1',
    'reporter_email' => 'reporter@example.org',
    'reporter_name' => 'Jane Doe',
    'description' => 'This is a fake login page that steals credentials from customers.',
]);
T::same(302, $response->statusCode(), 'valid submission redirects');
T::contains('/report/submitted/', location($response), 'redirects to the confirmation page');
T::same($before + 1, Ticket::countAll(), 'one ticket created');

$ticket = Ticket::search([], 1)[0] ?? null;
T::ok($ticket instanceof Ticket, 'ticket persisted');
$ticketNumber = (string) $ticket?->number();
T::ok((bool) preg_match('/^ABR-\d{8}-\d{4}$/', $ticketNumber), 'ticket number format ' . $ticketNumber);
T::same(Ticket::STATUS_NEW, $ticket?->status(), 'new ticket starts in status "new"');
T::same('phishing', $ticket?->abuseType(), 'abuse type stored');
T::same('evil.example', $ticket?->domain(), 'domain derived from URL');
T::same('https://evil.example/login?x=1', $ticket?->url(), 'URL stored as submitted');
T::same('web', $ticket?->get('source'), 'source recorded as web');
T::same(64, strlen((string) $ticket?->token()), '256-bit reporter token');
T::contains('/report/submitted/' . $ticket?->token(), location($response), 'redirect carries the token');

$submitted = req('GET', '/report/submitted/' . $ticket?->token());
T::same(200, $submitted->statusCode(), 'confirmation page renders');
T::contains($ticketNumber, $submitted->content(), 'confirmation shows the ticket number');

$status = req('GET', '/ticket/' . $ticket?->token());
T::same(200, $status->statusCode(), 'reporter status page renders');
T::contains('Report status', $status->content(), 'status page has the headline');
T::contains($ticketNumber, $status->content(), 'status page shows the ticket number');
T::contains('steals credentials', $status->content(), 'status page shows the description');
T::notContains('admin_notes', $status->content(), 'status page does not expose internal fields');

$invalid = req('POST', '/report', [
    'abuse_type' => 'phishing',
    'target' => 'https://bad.example/x',
    'reporter_email' => 'not-an-email',
    'description' => 'short',
]);
T::same(302, $invalid->statusCode(), 'invalid submission redirects back');
T::same($before + 1, Ticket::countAll(), 'invalid submission creates no ticket');

$noCsrf = req('POST', '/report', [
    'abuse_type' => 'phishing',
    'target' => 'https://bad.example/x',
    'reporter_email' => 'someone@example.org',
    'description' => 'A sufficiently long description for the honeypot test.',
], [], false);
T::same(419, $noCsrf->statusCode(), 'POST without CSRF token is rejected');

T::same(404, req('GET', '/ticket/deadbeefdeadbeefdeadbeefdeadbeef')->statusCode(), 'unknown token returns 404');
T::same(404, req('GET', '/this/does/not/exist')->statusCode(), 'unknown route returns 404');

// Evidence upload (service level) then download (HTTP level).
$evidenceService = new EvidenceService();
$temp = tempnam(sys_get_temp_dir(), 'ev') ?: storage_path('uploads/tmp.bin');
file_put_contents($temp, 'PNG-FAKE-BYTES');
$stored = $evidenceService->storeUploaded((int) $ticket?->id(), [
    'name' => 'screenshot.png',
    'type' => 'image/png',
    'tmp_name' => $temp,
    'error' => UPLOAD_ERR_OK,
    'size' => 15,
], 'reporter');
T::ok($stored instanceof Evidence, 'evidence stored');
T::ok($stored->exists(), 'evidence file exists on disk');
T::same('reporter', $stored->get('uploaded_by'), 'evidence attributed to the reporter');

$statusWithEvidence = req('GET', '/ticket/' . $ticket?->token());
T::contains('screenshot.png', $statusWithEvidence->content(), 'reporter sees attached evidence');

/* -------------------------------------------------------------------------
 * Admin sign-in over HTTP
 * ---------------------------------------------------------------------- */
T::suite('Admin sign-in (HTTP)');

$guard = req('GET', '/admin');
T::same(302, $guard->statusCode(), 'unauthenticated dashboard redirects');
T::contains('/admin/login', location($guard), 'redirect points at the login page');

$loginPage = req('GET', '/admin/login');
T::same(200, $loginPage->statusCode(), 'login page renders');
T::contains('one-time sign-in code', $loginPage->content(), 'login page explains the code flow');
T::notContains('type="password"', $loginPage->content(), 'login page has no password field');

$requestResponse = req('POST', '/admin/login', ['email' => 'tayyebimohammadreza@gmail.com']);
T::same(302, $requestResponse->statusCode(), 'code request redirects');
T::contains('/admin/login/verify', location($requestResponse), 'redirects to the code form');

$authResult = (new AuthService())->requestCode('tayyebimohammadreza@gmail.com', '203.0.113.50');
T::ok($authResult['ok'], 'code issued');
T::ok(is_string($authResult['debug_code']) && strlen((string) $authResult['debug_code']) === 6, 'debug mode exposes the code for testing');

$wrongCode = req('POST', '/admin/login/verify', ['code' => '000000']);
T::same(302, $wrongCode->statusCode(), 'wrong code redirects back');
T::ok(Session::adminId() === null, 'wrong code does not create a session');

$verify = req('POST', '/admin/login/verify', ['code' => (string) $authResult['debug_code']]);
T::same(302, $verify->statusCode(), 'correct code redirects');
T::same('/admin', location($verify), 'correct code lands on the dashboard');
T::ok(Session::adminId() !== null, 'session established');
T::same('tayyebimohammadreza@gmail.com', Session::adminEmail(), 'session stores the admin e-mail');

/* -------------------------------------------------------------------------
 * Admin UI
 * ---------------------------------------------------------------------- */
T::suite('Admin UI (HTTP)');

$ticket?->markEnriched([
    'generated_at' => date('c'),
    'dns' => ['a' => ['203.0.113.9'], 'mx' => ['10 mx.host.example'], 'ns' => ['ns1.host.example']],
    'whois' => ['available' => false, 'raw' => 'Domain Name: EVIL.EXAMPLE'],
    'rdap_domain' => ['registrar' => 'Example Registrar'],
    'http' => ['status' => 200, 'title' => 'Sign in', 'final_url' => 'https://evil.example/login'],
    'errors' => [],
]);

$dashboard = req('GET', '/admin');
T::same(200, $dashboard->statusCode(), 'dashboard renders');
T::contains('Dashboard', $dashboard->content(), 'dashboard heading');
T::contains($ticketNumber, $dashboard->content(), 'dashboard lists the new ticket');
T::contains('Background work', $dashboard->content(), 'dashboard explains piggyback processing');

$all = req('GET', '/admin/tickets?status=all');
T::same(200, $all->statusCode(), 'ticket list renders');
T::contains($ticketNumber, $all->content(), 'ticket list contains the ticket');

$filteredOut = req('GET', '/admin/tickets?status=resolved');
T::notContains($ticketNumber, $filteredOut->content(), 'status filter excludes non-matching tickets');

T::contains($ticketNumber, req('GET', '/admin/tickets?q=evil.example')->content(), 'search by domain works');

$detail = req('GET', '/admin/ticket/' . $ticket?->id());
T::same(200, $detail->statusCode(), 'ticket detail renders');
T::contains('Technical enrichment', $detail->content(), 'detail shows enrichment');
T::contains('203.0.113.9', $detail->content(), 'detail shows DNS data');
T::contains('Example Registrar', $detail->content(), 'detail shows RDAP data');
T::contains('Abuse contacts', $detail->content(), 'detail shows the contacts panel');

T::same(404, req('GET', '/admin/ticket/999999')->statusCode(), 'unknown ticket returns 404');

$download = req('GET', '/admin/ticket/' . $ticket?->id() . '/evidence/' . $stored->id());
T::same(200, $download->statusCode(), 'evidence download works');
T::same('PNG-FAKE-BYTES', $download->content(), 'evidence content is streamed');
T::contains('attachment', (string) $download->headerValue('Content-Disposition'), 'evidence is served as an attachment');

/* -------------------------------------------------------------------------
 * Contacts, approval, sending
 * ---------------------------------------------------------------------- */
T::suite('Contacts, approval and sending');

$addContact = req('POST', '/admin/ticket/' . $ticket?->id() . '/contacts', [
    'abuse_email' => 'abuse@host.example',
    'provider_name' => 'Example Host',
]);
T::same(302, $addContact->statusCode(), 'contact creation redirects');
$contacts = AbuseContact::forTicket((int) $ticket?->id());
T::same(1, count($contacts), 'contact stored');
T::same('abuse@host.example', $contacts[0]->email(), 'contact address stored');
T::same('manual', $contacts[0]->source(), 'manual contacts are labelled');

// Duplicate contacts are ignored thanks to the unique index.
$duplicate = AbuseContact::add((int) $ticket?->id(), 'abuse@host.example', 'whois');
T::same($contacts[0]->id(), $duplicate?->id(), 'duplicate contact is deduplicated');

$select = req('POST', '/admin/ticket/' . $ticket?->id() . '/contacts/' . $contacts[0]->id() . '/select');
T::same(302, $select->statusCode(), 'selecting a contact redirects');
T::ok(AbuseContact::selectedFor((int) $ticket?->id()) !== null, 'contact marked as selected');

$approve = req('POST', '/admin/ticket/' . $ticket?->id() . '/approve', ['contact_id' => (string) $contacts[0]->id()]);
T::same(302, $approve->statusCode(), 'approval redirects');
T::contains('/preview', location($approve), 'approval goes to the preview');
$ticket?->refresh();
T::same(Ticket::STATUS_APPROVED, $ticket?->status(), 'ticket approved');
T::ok($ticket?->get('approved_at') !== null, 'approval timestamp set');

$preview = req('GET', '/admin/ticket/' . $ticket?->id() . '/preview?recipient=abuse%40host.example&format=arf');
T::same(200, $preview->statusCode(), 'preview renders');
T::contains('Send report', $preview->content(), 'preview has the send button');
T::contains('message/feedback-report', $preview->content(), 'preview shows the ARF part');

$xarfPreview = req('GET', '/admin/ticket/' . $ticket?->id() . '/preview?recipient=abuse%40host.example&format=xarf');
T::contains('application/json', $xarfPreview->content(), 'X-ARF preview renders');

$outboxBefore = outboxCount();
$send = req('POST', '/admin/ticket/' . $ticket?->id() . '/send', [
    'recipient_email' => 'abuse@host.example',
    'format' => 'arf',
    'subject' => '[ABR] Phishing report for evil.example',
    'body' => 'Analyst reviewed draft body.',
]);
T::same(302, $send->statusCode(), 'sending redirects');
$ticket?->refresh();
T::same(Ticket::STATUS_REPORTED, $ticket?->status(), 'ticket becomes reported');
T::ok($ticket?->get('reported_at') !== null, 'reported timestamp set');

$reports = OutboundReport::forTicket((int) $ticket?->id());
T::same(1, count($reports), 'outbound report recorded');
$report = $reports[0] ?? null;
T::same('sent', $report?->get('send_status'), 'report marked as sent');
T::same('abuse@host.example', $report?->recipient(), 'recipient recorded');
T::same('arf', $report?->get('format'), 'format recorded');
T::contains('Feedback-Type: phishing', $report->rawMime(), 'stored MIME contains the feedback report');
T::contains('Analyst reviewed draft body.', $report->rawMime(), 'edited body is used');
T::ok($report?->get('smtp_message_id') !== null, 'Message-ID stored for reply matching');
T::ok(outboxCount() > $outboxBefore, 'reporter notification queued');
T::contains('reporter@example.org', outboxContents(), 'reporter was notified');

$downloadReport = req('GET', '/admin/ticket/' . $ticket?->id() . '/report/' . $report?->id());
T::same(200, $downloadReport->statusCode(), 'sent MIME can be downloaded');
T::contains('multipart/report', $downloadReport->content(), 'downloaded MIME is a report');

/* -------------------------------------------------------------------------
 * Inbox and reply matching
 * ---------------------------------------------------------------------- */
T::suite('Inbox and reply matching');

$processor = new MailboxProcessor();

$matchedByThread = $processor->matchTicket([
    'in_reply_to' => (string) $report?->get('smtp_message_id'),
    'references' => [],
    'subject' => 'Re: abuse report',
    'from' => 'abuse@host.example',
]);
T::same($ticket?->id(), $matchedByThread?->id(), 'reply matched via In-Reply-To');

$matchedBySubject = $processor->matchTicket([
    'in_reply_to' => '',
    'references' => [],
    'subject' => 'Re: [' . $ticketNumber . '] Phishing report',
    'from' => 'someone@host.example',
]);
T::same($ticket?->id(), $matchedBySubject?->id(), 'reply matched via ticket number in subject');

$matchedByDomain = $processor->matchTicket([
    'in_reply_to' => '',
    'references' => [],
    'subject' => 'Your report',
    'from' => 'abuse@host.example',
]);
T::same($ticket?->id(), $matchedByDomain?->id(), 'reply matched via provider domain');

$unmatched = $processor->matchTicket([
    'in_reply_to' => '',
    'references' => [],
    'subject' => 'Hello',
    'from' => 'stranger@elsewhere.example',
]);
T::same(null, $unmatched?->id(), 'unknown sender stays unmatched');

$inbound = InboundMessage::store([
    'mailbox' => 'responses',
    'from_address' => 'abuse@host.example',
    'to_address' => 'abuse@abuse-desk.local',
    'subject' => 'Re: [' . $ticketNumber . '] Phishing report',
    'body_text' => 'Thanks, we suspended the account.',
    'raw_mime' => "From: abuse@host.example\r\nSubject: Re: [" . $ticketNumber . "]\r\n\r\nThanks, we suspended the account.\r\n",
    'message_id' => 'reply-1@host.example',
    'in_reply_to' => (string) $report?->get('smtp_message_id'),
    'is_matched' => 'FALSE',
    'is_processed' => 'TRUE',
    'received_at' => date('Y-m-d H:i:s'),
]);
T::ok($inbound instanceof InboundMessage, 'inbound reply stored');
T::same(null, InboundMessage::store([
    'mailbox' => 'responses',
    'message_id' => 'reply-1@host.example',
]), 'duplicate inbound message is ignored');

$inbox = req('GET', '/admin/inbox?mailbox=responses');
T::same(200, $inbox->statusCode(), 'inbox renders');
T::contains('Phishing report', $inbox->content(), 'inbox lists the reply');

$inboxMessage = req('GET', '/admin/inbox/' . $inbound?->id());
T::same(200, $inboxMessage->statusCode(), 'message detail renders');
T::contains('abuse@host.example', $inboxMessage->content(), 'message detail shows the sender');

$link = req('POST', '/admin/inbox/' . $inbound?->id() . '/link', ['ticket' => $ticketNumber]);
T::same(302, $link->statusCode(), 'manual link redirects');
$inbound?->refresh();
T::ok($inbound?->isMatched() ?? false, 'message matched after linking');
T::same($ticket?->id(), (int) $inbound?->get('ticket_id'), 'message points at the ticket');

$ticketDetailAfterReply = req('GET', '/admin/ticket/' . $ticket?->id());
T::contains('we suspended the account', $ticketDetailAfterReply->content(), 'reply shown on the ticket');

// Intake message → ticket creation.
$intake = InboundMessage::store([
    'mailbox' => 'intake',
    'from_address' => 'victim@example.net',
    'subject' => 'Suspicious site',
    'body_text' => 'Please look at https://malware-host.example/payload.exe — it is malware.',
    'raw_mime' => "From: victim@example.net\r\nSubject: Suspicious site\r\n\r\nPlease look at https://malware-host.example/payload.exe\r\n",
    'message_id' => 'intake-1@example.net',
    'is_matched' => 'FALSE',
    'is_processed' => 'FALSE',
    'received_at' => date('Y-m-d H:i:s'),
]);
$ticketsBefore = Ticket::countAll();
$createFromMessage = req('POST', '/admin/inbox/' . $intake?->id() . '/create-ticket');
T::same(302, $createFromMessage->statusCode(), 'creating a ticket from a message redirects');
T::same($ticketsBefore + 1, Ticket::countAll(), 'ticket created from the intake message');
$intake?->refresh();
T::ok($intake?->isMatched() ?? false, 'intake message linked to the new ticket');

/* -------------------------------------------------------------------------
 * Notes, resolution, reporter view
 * ---------------------------------------------------------------------- */
T::suite('Notes and resolution');

$notes = req('POST', '/admin/ticket/' . $ticket?->id() . '/notes', ['admin_notes' => 'Provider confirmed suspension.']);
T::same(302, $notes->statusCode(), 'saving notes redirects');
$ticket?->refresh();
T::same('Provider confirmed suspension.', $ticket?->get('admin_notes'), 'notes stored');

$resolve = req('POST', '/admin/ticket/' . $ticket?->id() . '/status', [
    'action' => 'resolve',
    'note' => 'Hosting provider removed the phishing page.',
]);
T::same(302, $resolve->statusCode(), 'resolve redirects');
$ticket?->refresh();
T::same(Ticket::STATUS_RESOLVED, $ticket?->status(), 'ticket resolved');
T::ok($ticket?->get('resolved_at') !== null, 'resolution timestamp set');

$reporterView = req('GET', '/ticket/' . $ticket?->token());
T::contains('Resolved', $reporterView->content(), 'reporter sees the resolved status');
T::contains('abuse@host.example', $reporterView->content(), 'reporter sees who the report was forwarded to');
T::notContains('Provider confirmed suspension.', $reporterView->content(), 'internal notes stay internal');

$reopen = req('POST', '/admin/ticket/' . $ticket?->id() . '/status', ['action' => 'reopen']);
$ticket?->refresh();
T::same(Ticket::STATUS_REPORTED, $ticket?->status(), 'reopening returns to reported');
T::same(null, $ticket?->get('resolved_at'), 'resolution timestamp cleared');

$reject = req('POST', '/admin/ticket/' . $ticket?->id() . '/status', ['action' => 'reject', 'note' => 'Out of scope']);
$ticket?->refresh();
T::same(Ticket::STATUS_REJECTED, $ticket?->status(), 'ticket can be rejected');

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */
T::suite('Settings');

$settings = req('GET', '/admin/settings');
T::same(200, $settings->statusCode(), 'settings page renders');
T::contains('Administrators', $settings->content(), 'admins section present');
T::contains('tayyebimohammadreza@gmail.com', $settings->content(), 'seeded admin listed');
T::contains('Piggyback processing', $settings->content(), 'piggyback section present');
T::notContains('type="password"', $settings->content(), 'no password fields anywhere');

$addAdmin = req('POST', '/admin/settings/admins', ['email' => 'second-analyst@example.org']);
T::same(302, $addAdmin->statusCode(), 'adding an admin redirects');
$second = Admin::findByEmail('second-analyst@example.org');
T::ok($second !== null, 'second administrator stored');
T::ok($second?->isActive() ?? false, 'new administrator is active');

$toggle = req('POST', '/admin/settings/admins/' . $second?->id() . '/toggle', ['active' => '0']);
$second?->refresh();
T::ok(!($second?->isActive() ?? true), 'administrator can be disabled');

$selfToggle = req('POST', '/admin/settings/admins/' . Session::adminId() . '/toggle', ['active' => '0']);
T::same(302, $selfToggle->statusCode(), 'self-disable is refused (redirect, no crash)');
$me = Admin::find(Session::adminId());
T::ok($me?->isActive() ?? false, 'an admin cannot lock themselves out');

/* -------------------------------------------------------------------------
 * Model / state behaviour
 * ---------------------------------------------------------------------- */
T::suite('Models and system state');

SystemState::set('test_key', 'hello');
T::same('hello', SystemState::get('test_key'), 'system state round-trip');
SystemState::touch('test_key');
T::ok(SystemState::isFresh('test_key', 60), 'fresh timestamp detected');
SystemState::setJson('test_json', ['a' => 1]);
T::same(['a' => 1], SystemState::getJson('test_json'), 'JSON state round-trip');

$counts = Ticket::countsByStatus();
T::ok(isset($counts[Ticket::STATUS_REJECTED]), 'status counters populated');
T::same(Ticket::countAll(), array_sum($counts), 'counters add up to the total');

T::ok(Ticket::needingEnrichment(5) !== [], 'tickets pending enrichment are found');

$enriched = Ticket::search(['q' => 'evil.example'], 1)[0] ?? null;
T::same('203.0.113.9', ($enriched?->enrichment()['dns']['a'][0] ?? null), 'JSONB enrichment hydrates to an array');

T::same([], Ticket::search(['q' => 'no-such-thing-anywhere']), 'search without matches returns empty');

$secondNumber = Ticket::nextTicketNumber();
T::ok((bool) preg_match('/^ABR-\d{8}-\d{4}$/', $secondNumber), 'next ticket number is valid');

$stats = InboundMessage::stats();
T::same(2, $stats['intake'] + $stats['responses'], 'inbound stats count stored messages');

// Enrichment helpers that must not touch the network.
$enricher = new \App\Services\EnrichmentService();
T::same([], $enricher->dnsLookup('not a domain'), 'invalid domains are not resolved');
$probe = $enricher->httpProbe('http://127.0.0.1/admin');
T::same('target resolves to a private or reserved address', $probe['skipped'] ?? null, 'SSRF guard refuses private targets');

/* -------------------------------------------------------------------------
 * Logout
 * ---------------------------------------------------------------------- */
T::suite('Logout');

$logout = req('POST', '/admin/logout');
T::same(302, $logout->statusCode(), 'logout redirects');
T::same(null, Session::adminId(), 'session cleared');
T::same(302, req('GET', '/admin')->statusCode(), 'dashboard requires sign-in again');

exit(T::summary());
