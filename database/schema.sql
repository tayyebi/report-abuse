-- ---------------------------------------------------------------------------
-- Abuse Report Desk — PostgreSQL schema
--
-- Safe to re-run: every statement is idempotent (IF NOT EXISTS).
-- Apply with:  php bin/console migrate
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Administrators
--
-- There is deliberately NO password column and NO username column: admin
-- accounts are just an id + email address. Authentication happens with a
-- short-lived one-time code emailed to that address.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id              SERIAL PRIMARY KEY,
    email           VARCHAR(255) UNIQUE NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at   TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

-- One-time login codes (only a hash of the code is stored).
CREATE TABLE IF NOT EXISTS admin_login_codes (
    id              SERIAL PRIMARY KEY,
    admin_id        INTEGER NOT NULL REFERENCES admins(id) ON DELETE CASCADE,
    code_hash       VARCHAR(255) NOT NULL,
    attempts        SMALLINT NOT NULL DEFAULT 0,
    requested_ip    VARCHAR(45),
    expires_at      TIMESTAMP NOT NULL,
    used_at         TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_codes_admin ON admin_login_codes (admin_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_codes_expiry ON admin_login_codes (expires_at);

-- ---------------------------------------------------------------------------
-- Tickets: core entity
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
    id                  SERIAL PRIMARY KEY,
    ticket_number       VARCHAR(20) UNIQUE NOT NULL,   -- ABR-20261106-0001
    status              VARCHAR(30) NOT NULL DEFAULT 'new',
    abuse_type          VARCHAR(30) NOT NULL DEFAULT 'other',

    -- Reported target
    reported_url        TEXT,
    reported_domain     VARCHAR(255),
    reported_ip         VARCHAR(45),

    -- Reporter info
    reporter_email      VARCHAR(255) NOT NULL,
    reporter_name       VARCHAR(255),
    reporter_ip         VARCHAR(45),
    reporter_token      VARCHAR(64) NOT NULL,
    description         TEXT,

    -- Source channel
    source              VARCHAR(20) NOT NULL DEFAULT 'web',   -- web | email
    source_message_id   VARCHAR(255),

    -- Admin
    admin_notes         TEXT,
    approved_at         TIMESTAMP,
    reported_at         TIMESTAMP,
    resolved_at         TIMESTAMP,

    -- Lazy enrichment cache
    enrichment_data     JSONB NOT NULL DEFAULT '{}'::jsonb,
    enrichment_done     BOOLEAN NOT NULL DEFAULT FALSE,
    enrichment_at       TIMESTAMP,

    created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets (status);
CREATE INDEX IF NOT EXISTS idx_tickets_token ON tickets (reporter_token);
CREATE INDEX IF NOT EXISTS idx_tickets_created ON tickets (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tickets_domain ON tickets (reported_domain);
CREATE INDEX IF NOT EXISTS idx_tickets_source_message ON tickets (source_message_id);
CREATE INDEX IF NOT EXISTS idx_tickets_reporter ON tickets (reporter_email);

-- ---------------------------------------------------------------------------
-- Evidence files
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evidence (
    id          SERIAL PRIMARY KEY,
    ticket_id   INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    filename    VARCHAR(255) NOT NULL,
    filepath    VARCHAR(500) NOT NULL,
    filetype    VARCHAR(50),                        -- screenshot, eml, header, document
    filesize    INTEGER,
    mime_type   VARCHAR(120),
    sha256      VARCHAR(64),
    uploaded_by VARCHAR(20) NOT NULL DEFAULT 'reporter',  -- reporter | system | admin
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_evidence_ticket ON evidence (ticket_id);

-- ---------------------------------------------------------------------------
-- Abuse contacts discovered or entered manually
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS abuse_contacts (
    id              SERIAL PRIMARY KEY,
    ticket_id       INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    provider_name   VARCHAR(255),
    abuse_email     VARCHAR(255) NOT NULL,
    source          VARCHAR(30),                    -- whois | rdap | abusix | rfc2142 | manual
    is_selected     BOOLEAN NOT NULL DEFAULT FALSE,
    raw_lookup      JSONB,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_contacts_ticket ON abuse_contacts (ticket_id);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_contacts_ticket_email ON abuse_contacts (ticket_id, abuse_email);

-- ---------------------------------------------------------------------------
-- Outbound reports sent to abuse desks
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS outbound_reports (
    id                  SERIAL PRIMARY KEY,
    ticket_id           INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    abuse_contact_id    INTEGER REFERENCES abuse_contacts(id) ON DELETE SET NULL,
    format              VARCHAR(20) NOT NULL,       -- arf | xarf | plaintext
    recipient_email     VARCHAR(255) NOT NULL,
    subject             VARCHAR(500),
    body                TEXT,
    raw_mime            TEXT,
    smtp_message_id     VARCHAR(255),
    send_status         VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft | sent | failed
    error_message       TEXT,
    sent_at             TIMESTAMP,
    created_at          TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_outbound_ticket ON outbound_reports (ticket_id);
CREATE INDEX IF NOT EXISTS idx_outbound_message_id ON outbound_reports (smtp_message_id);

-- ---------------------------------------------------------------------------
-- Inbound messages (responses from abuse desks + intake emails)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inbound_messages (
    id              SERIAL PRIMARY KEY,
    ticket_id       INTEGER REFERENCES tickets(id) ON DELETE SET NULL,
    mailbox         VARCHAR(20) NOT NULL DEFAULT 'responses',   -- intake | responses
    from_address    VARCHAR(255),
    to_address      VARCHAR(255),
    subject         VARCHAR(500),
    body_text       TEXT,
    body_html       TEXT,
    raw_mime        TEXT,
    message_id      VARCHAR(255),
    in_reply_to     VARCHAR(255),
    imap_uid        INTEGER,
    imap_uidvalidity BIGINT,
    is_matched      BOOLEAN NOT NULL DEFAULT FALSE,
    is_processed    BOOLEAN NOT NULL DEFAULT FALSE,
    error_message   TEXT,
    received_at     TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_inbound_message_id ON inbound_messages (message_id);
CREATE INDEX IF NOT EXISTS idx_inbound_in_reply_to ON inbound_messages (in_reply_to);
CREATE INDEX IF NOT EXISTS idx_inbound_ticket ON inbound_messages (ticket_id);
CREATE INDEX IF NOT EXISTS idx_inbound_mailbox ON inbound_messages (mailbox, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_inbound_message_id ON inbound_messages (message_id);

-- ---------------------------------------------------------------------------
-- Audit log / timeline
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_events (
    id          SERIAL PRIMARY KEY,
    ticket_id   INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    event_type  VARCHAR(50) NOT NULL,
    actor       VARCHAR(50),                        -- system | admin | reporter
    actor_email VARCHAR(255),
    summary     VARCHAR(500),
    details     JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_events_ticket ON ticket_events (ticket_id, created_at);

-- ---------------------------------------------------------------------------
-- System state (drives the "no cron" piggyback processing)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_state (
    key         VARCHAR(64) PRIMARY KEY,
    value       TEXT,
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

INSERT INTO system_state (key, value)
VALUES
    ('last_intake_check', '2000-01-01 00:00:00'),
    ('last_response_check', '2000-01-01 00:00:00'),
    ('last_intake_report', '{}'),
    ('last_response_report', '{}')
ON CONFLICT (key) DO NOTHING;
