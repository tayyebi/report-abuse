# Abuse Report Desk

A small, self-hosted abuse-report handling system in **pure PHP 8.2+ and PostgreSQL** — no
framework, no Composer dependencies, no cron jobs, no queue workers.

Reporters submit a phishing/malware/spam/fraud report through a web form or by e-mail. An analyst
reviews it, the system enriches the target (DNS, WHOIS, RDAP, HTTP), finds the responsible abuse
contact, and sends a standards-compliant **ARF (RFC 5965)** or **X-ARF** report over SMTP. Provider
replies are pulled from IMAP, matched back to the ticket, and the reporter is notified when the case
is forwarded or resolved.

---

## Highlights

| | |
|---|---|
| **Stack** | PHP 8.2+, PostgreSQL 13+, Apache (or PHP built-in server) |
| **Dependencies** | none — no Composer packages, no PHPMailer, no template engine |
| **Admin login** | passwordless: e-mail address → one-time code → session. **No password and no username are stored**, only `id` + `email` |
| **Background work** | none scheduled: IMAP polling and enrichment run *inside admin page loads* (“piggyback processing”) |
| **Outbound** | ARF (`multipart/report; report-type=feedback-report`), X-ARF JSON, or plain text over a hand-written SMTP client |
| **Inbound** | hand-written IMAP4rev1 client (works on PHP 8.4 where `ext-imap` was removed) |
| **Deploy** | `docker compose up -d --build` (app + PostgreSQL + Mailpit) |

---

## Quick start (Docker)

```bash
git clone <this repo> abuse-desk && cd abuse-desk
docker compose up -d --build
```

| Service | URL | Notes |
|---|---|---|
| Web UI | <http://localhost:8080> | public report form |
| Staff area | <http://localhost:8080/admin> | seeded admin: `tayyebimohammadreza@gmail.com` |
| Mailpit | <http://localhost:8025> | every outgoing e-mail lands here — read your sign-in code |

Sign in:

1. Open <http://localhost:8080/admin>.
2. Enter the seeded administrator address (`ADMIN_EMAIL`, default
   `tayyebimohammadreza@gmail.com`).
3. Open Mailpit, read the 6-digit code, enter it. The code is valid for 10 minutes and only once.

> No password is created, stored, hashed or transmitted anywhere. The `admins` table only has
> `id`, `email`, `is_active`, `last_login_at`, `created_at`.

Configuration lives in `docker-compose.yml` (environment variables) with defaults that work
out of the box. Copy `.env.example` to `.env` if you want to override values with
`docker compose --env-file .env up`.

### Try the full loop locally

```bash
# 1. Submit a report at http://localhost:8080
# 2. Sign in at /admin, open the ticket, "Re-enrich", review the contact, approve, preview, send
# 3. The ARF report appears in Mailpit (SMTP 1025)
# 4. Reply to that message inside Mailpit
# 5. Load /admin again — the reply is pulled from IMAP and matched to the ticket
```

---

## Manual installation (no Docker)

Requirements: PHP ≥ 8.2 with `pdo_pgsql`, `openssl`, `fileinfo` (recommended: `mbstring`,
`whois` binary), PostgreSQL ≥ 13, and a web server whose document root is `public/`.

```bash
cp .env.example .env
# edit .env: DB_*, ADMIN_EMAIL, SMTP_*, IMAP_*, APP_URL, APP_KEY

php bin/console migrate          # create tables (idempotent)
php bin/console seed             # create the first administrator (ADMIN_EMAIL)
php bin/console admin:list       # verify

# manual alternative for the seeder:
# psql -U abuse -d abuse_desk -f database/seed.sql

# development server
php -S 0.0.0.0:8080 -t public
```

Apache: point the `DocumentRoot` at `public/` and allow `.htaccess` (`AllowOverride All`). A
front-controller `.htaccess` ships in `public/`. If you cannot change the document root, the
`.htaccess` in the repository root rewrites into `public/` and blocks access to internals.

Nginx equivalent:

```nginx
root /var/www/abuse-desk/public;
index index.php;

location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
location ~ ^/(storage|src|config|database|bin)/ { deny all; }
```

---

## How it works

### Channels

```
Web form ─────────────┐
                      ├──> tickets ──> enrichment ──> abuse contacts ──> ARF/X-ARF ──> SMTP
Inbound e-mail (IMAP) ┘                                        ▲                        │
                                                               │                        ▼
Reporter status page <── notifications ── resolved <── matching <── IMAP replies ◀── abuse desk
```

1. **Intake** — `POST /report` creates a ticket, stores evidence, returns a secret status link, and
   e-mails the reporter. The intake IMAP mailbox does the same for messages sent to the abuse desk.
2. **Enrichment** — lazy, on first admin view (or “Re-enrich”): DNS, WHOIS (if the binary exists),
   RDAP for the domain and the IP, plus a guarded HTTP probe (status, final URL, title).
3. **Contact discovery** — RDAP entities with `role=abuse`, WHOIS `abuse-mailbox`/`OrgAbuseEmail`
   style addresses, the Abusix DNS zone, and the RFC 2142 `abuse@domain` convention.
4. **Approval** — the analyst selects the recipient and approves; the preview screen shows the exact
   MIME message, and the human-readable part is editable.
5. **Sending** — raw-socket SMTP with STARTTLS/AUTH, the message is stored in `outbound_reports`
   (including the full MIME), the ticket becomes `reported`, and the reporter is notified.
6. **Replies** — the response mailbox is polled lazily; messages are matched by `In-Reply-To` /
   `References`, by the `ABR-…` ticket number in the subject, or by provider domain.
7. **Resolution** — the analyst resolves the ticket (with a note); the reporter receives the outcome.

### The “no cron” strategy

There is no scheduler. `PiggybackService` runs at the start of admin page loads:

* if `system_state.last_intake_check` / `last_response_check` is older than `IMAP_CHECK_INTERVAL`
  (default 300 s), the corresponding mailbox is polled once;
* the state row is touched *before* the work starts, so parallel requests cannot stampede;
* pending new tickets are enriched a few at a time (`ENRICHMENT_BATCH`), with a hard time budget;
* reporter-facing pages never trigger background work — they only read.

Everything is driven by the PostgreSQL clock (`system_state`), so container/host clock skew is not an
issue.

### Ticket lifecycle

```
new ──approve──> approved ──send──> reported ──resolve──> resolved ──close──> closed
 │                   │                  │
 └────reject─────────┴──────reject──────┘        (reopen returns to new/reported)
```

Every transition writes a `ticket_events` row (actor, summary, JSON details) shown as the ticket
timeline.

---

## Staff authentication (passwordless by design)

* `admins` stores **only** `id`, `email`, `is_active`, `last_login_at`, `created_at`.
* Sign-in = request a code for a known, active address → `admin_login_codes` row with
  `password_hash(code)`, TTL (`LOGIN_CODE_TTL`, default 600 s), attempt counter
  (`LOGIN_CODE_MAX_ATTEMPTS`, default 5) and a single-use marker.
* Rate limit: `LOGIN_CODE_REQUEST_MAX` codes per `LOGIN_CODE_WINDOW` seconds per address.
* Issuing a new code invalidates older unused ones; successful verification is atomic
  (`UPDATE … WHERE used_at IS NULL` must affect exactly one row).
* Unknown addresses get the same “code sent” response — no account enumeration.
* Session cookie is `HttpOnly`, `SameSite=Lax`, `Secure` behind HTTPS; the session id is regenerated
  on login and after `SESSION_LIFETIME` of inactivity.
* With `APP_DEBUG=true` the code is also echoed as a development hint, so you can test without a
  working mailbox. Leave debug off in production.

Add or disable staff:

```bash
php bin/console admin:add colleague@example.org
php bin/console admin:list
php bin/console admin:disable colleague@example.org
```

---

## Project layout

```
.
├── public/                  web root (index.php front controller, assets/)
├── src/
│   ├── Core/                App (router), Database, Request, Response, Session, View, Config
│   ├── Controllers/         Public, Auth, Admin, Inbox (+ shared base Controller)
│   ├── Models/              Ticket, Evidence, AbuseContact, OutboundReport, InboundMessage,
│   │                        TicketEvent, Admin, LoginCode, SystemState, Model (base)
│   ├── Services/            TicketService, EnrichmentService, AbuseContactFinder, ArfFormatter,
│   │                        XarfFormatter, ReportComposer, SmtpMailer, ImapClient, EmailParser,
│   │                        MailboxProcessor, NotificationService, AuthService, PiggybackService,
│   │                        EvidenceService, Templates, MimeBuilder, ReportData
│   ├── Views/               layout.php, public/, admin/, auth/, errors/
│   ├── bootstrap.php        autoloader + .env loader
│   └── helpers.php          env(), e(), time_ago(), …
├── config/
│   ├── app.php database.php mail.php
│   └── templates/           ARF/reporter/admin e-mail templates ({{placeholders}})
├── database/schema.sql      full schema, idempotent
├── docker/                  Dockerfile helpers (entrypoint, php.ini)
├── bin/console              migrations, seeding, admin & mail commands
├── docker-compose.yml       app + PostgreSQL + Mailpit
└── tests/                   dependency-free test runner (see below)
```

Uploads live in `storage/uploads` (outside the web root, streamed through a controller) along with
`storage/outbox` (log transport) and `storage/logs`.

---

## Routes

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | public report form |
| POST | `/report` | create ticket from the form |
| GET | `/report/submitted/{token}` | confirmation page |
| GET | `/ticket/{token}` | reporter status page (private link) |
| POST | `/ticket/{token}/evidence` | reporter adds evidence |
| GET/POST | `/admin/login` | request a one-time code |
| GET/POST | `/admin/login/verify` | submit the code |
| POST | `/admin/logout` | sign out |
| GET | `/admin` | dashboard (runs piggyback work) |
| GET | `/admin/tickets` | queue with filters and pagination |
| GET | `/admin/ticket/{id}` | ticket detail (lazy enrichment) |
| POST | `/admin/ticket/{id}/enrich` | force re-enrichment |
| POST | `/admin/ticket/{id}/contacts` | add abuse contact |
| POST | `/admin/ticket/{id}/contacts/{cid}/select` · `/delete` | manage contacts |
| POST | `/admin/ticket/{id}/approve` | approve with a recipient |
| GET | `/admin/ticket/{id}/preview` | ARF/X-ARF draft preview |
| POST | `/admin/ticket/{id}/send` | send the report |
| POST | `/admin/ticket/{id}/status` | resolve / reject / close / reopen |
| POST | `/admin/ticket/{id}/notes` · `/evidence` | internal notes, extra evidence |
| GET | `/admin/ticket/{id}/evidence/{eid}` · `/report/{rid}` | download evidence / sent MIME |
| GET | `/admin/inbox` | intake + responses (runs piggyback check) |
| POST | `/admin/inbox/check` | poll both mailboxes now |
| GET | `/admin/inbox/{id}` | message detail |
| POST | `/admin/inbox/{id}/link` · `/create-ticket` | link manually / create ticket |
| GET | `/admin/settings` | admins, mail, piggyback state, system checks |
| POST | `/admin/settings/admins` · `/admins/{id}/toggle` | manage administrators |
| POST | `/admin/settings/mail-test` · `/imap-check` | test SMTP / poll IMAP |

All state-changing requests require a CSRF token tied to the session.

---

## Report formats

* **ARF (default)** — `multipart/report; report-type=feedback-report` with three parts:
  editable `text/plain`, `message/feedback-report` (`Feedback-Type`, `Reported-URI`,
  `Reported-Domain`, `Source-IP`, `Arrival-Date`, …) and a `text/rfc822-headers` evidence part.
* **X-ARF** — `multipart/report` whose machine part is an `application/json` X-ARF v3 document
  (`Version`, `ReporterInfo[]`, `ReportType`, `Report[]`, `Attachment[]`), plus a text summary.
* **Plain text** — a simple e-mail with the same content, for desks that ask for it.

Messages carry `Auto-Submitted: auto-generated`, a stable `Message-ID` and an `X-Abuse-Ticket` header;
the ticket number is in the subject so replies can be threaded back automatically.

Templates are plain text files in `config/templates/` (`arf_phishing.txt`, `arf_malware.txt`,
`arf_spam.txt`, `arf_generic.txt`, `reporter_*.txt`, `admin_login_code.txt`, …) with
`{{placeholder}}` substitution.

---

## Configuration reference

| Variable | Default | Meaning |
|---|---|---|
| `APP_NAME`, `APP_URL` | Abuse Report Desk, `http://localhost:8080` | branding, absolute links in e-mails |
| `APP_DEBUG` | `false` | show exceptions and echo the login code (dev only) |
| `APP_TIMEZONE` | `UTC` | PHP timezone |
| `DB_HOST/PORT/NAME/USER/PASSWORD/SSLMODE` | db:5432 abuse_desk | PostgreSQL connection |
| `ADMIN_EMAIL` | `tayyebimohammadreza@gmail.com` | seed administrator |
| `MAIL_TRANSPORT` | `smtp` | `smtp` or `log` (writes `storage/outbox/*.eml`) |
| `SMTP_HOST/PORT/ENCRYPTION/USER/PASSWORD` | — | raw-socket SMTP (`none`/`tls`/`ssl`) |
| `MAIL_FROM`, `MAIL_FROM_NAME` | abuse@abuse-desk.local | envelope/header sender |
| `IMAP_HOST/PORT/ENCRYPTION/USER/PASSWORD` | — | IMAP account (empty host = disabled) |
| `IMAP_INTAKE_MAILBOX`, `IMAP_RESPONSE_MAILBOX` | INBOX, INBOX | mailboxes to poll |
| `IMAP_CHECK_INTERVAL` | 300 | seconds between piggyback polls |
| `ENRICHMENT_INTERVAL`, `ENRICHMENT_BATCH` | 900, 3 | enrichment freshness and batch size |
| `LOGIN_CODE_TTL`, `LOGIN_CODE_MAX_ATTEMPTS` | 600, 5 | one-time code policy |
| `UPLOAD_MAX_SIZE`, `MAX_EVIDENCE_FILES` | 10 MiB, 5 | upload limits |
| `SUBMIT_RATE_LIMIT_PER_HOUR` | 10 | web submissions per IP per hour |
| `REPORT_REPORTER_ORG/DOMAIN/EMAIL`, `REPORT_DEFAULT_FORMAT` | — | identity and format of outbound reports |

`APP_KEY` is reserved for deployment-specific secrets; no credential is stored in the database.

---

## Console commands

```bash
php bin/console migrate [--fresh]   # apply schema (--fresh drops everything, then seeds)
php bin/console seed [email]        # create the bootstrap administrator
php bin/console admin:add <email>   # add staff (no password — code login only)
php bin/console admin:list
php bin/console admin:disable|enable <email>
php bin/console mail:test <email>   # verify SMTP
php bin/console mail:imap-test      # verify IMAP login
php bin/console mail:check          # poll both mailboxes immediately
php bin/console ticket:enrich       # enrich pending tickets
php bin/console prune               # drop expired login codes
php bin/console status              # counters
```

---

## Tests

Two dependency-free suites (no PHPUnit, no Composer):

```bash
php tests/run.php          # unit tests: no database needed
php tests/integration.php  # end-to-end: drops and recreates tables
```

`tests/run.php` covers target normalisation, e-mail/MIME parsing, ARF & X-ARF assembly, header
encoding, dot-stuffing, template rendering, abuse-contact extraction and helpers.

`tests/integration.php` drives the real front controller (`App::handle(Request::capture())`) against
PostgreSQL: schema, passwordless sign-in, public intake, admin UI, contacts/approval/sending,
inbox matching, resolution and settings. **It drops the tables in the configured database**, so use a
disposable one:

```bash
DB_NAME=abuse_desk_test DB_USER=postgres DB_PASSWORD=postgres php tests/integration.php
```

---

## Security notes

* CSRF tokens on every POST; session id regenerated on login; idle timeout.
* Output is escaped through `e()` everywhere; no raw `echo` of user input.
* Uploads: extension + MIME allow-list, size limit, random filenames, stored outside the web root,
  served only through an authenticated controller with `Content-Disposition: attachment`.
* The HTTP probe refuses private/reserved address space (basic SSRF guard) and only allows
  80/443/8080/8443.
* Reporter status links use a 256-bit random token; the token never appears in admin screenshots of
  internal lists.
* Login codes are hashed, single-use, expire, are rate-limited and attempt-limited. Unknown
  addresses get a uniform response.
* `.env`, `config/`, `src/`, `database/`, `storage/`, `bin/` are denied by the root `.htaccess`;
  put the document root on `public/` when possible.

---

## Design decisions vs. the original plan

| Plan | Implementation | Why |
|---|---|---|
| `admins(username, password_hash, totp_secret)` | `admins(id, email, …)` + `admin_login_codes` | requested: no stored password, no username, e-mail codes only |
| php-imap extension | pure-PHP `ImapClient` over sockets | `ext-imap` was removed from PHP 8.4 and is absent from `php:apache` images |
| uploads in `public/uploads` | `storage/uploads` streamed by a controller | keeps evidence out of the web root |
| ARF preview only | ARF + X-ARF + plain text, editable human part | matches the “format” column and real-world desks |
| WHOIS via `shell_exec` | optional: skipped gracefully when the binary is missing | works on hosts that disable `shell_exec`; RDAP covers it |
| `last_check` timestamps as text | `system_state` + `NOW()` comparisons | no clock-skew bugs |

---

## Licence

MIT — do whatever helps make the internet slightly less awful.
