-- =============================================================
--  Školní Portál – PostgreSQL schema
--  Generated: 2026-02-25
--  Run this on a clean database:
--    psql -U postgres -d <your_db_name> -f schema.sql
-- =============================================================

-- Drop tables in reverse dependency order (safe re-run)
DROP TABLE IF EXISTS report_messages CASCADE;
DROP TABLE IF EXISTS reports          CASCADE;
DROP TABLE IF EXISTS categories       CASCADE;
DROP TABLE IF EXISTS users            CASCADE;

-- =============================================================
--  users
-- =============================================================
CREATE TABLE users (
    id            SERIAL       PRIMARY KEY,
    username      VARCHAR(100) NOT NULL UNIQUE,
    email         VARCHAR(255),
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'user'
                  CHECK (role IN ('user', 'moderator', 'admin')),
    is_blocked    BOOLEAN      NOT NULL DEFAULT FALSE
);

-- =============================================================
--  categories
-- =============================================================
CREATE TABLE categories (
    id   SERIAL      PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

-- =============================================================
--  reports
-- =============================================================
CREATE TABLE reports (
    id          SERIAL       PRIMARY KEY,
    category_id INTEGER      NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    location    VARCHAR(100),
    user_id     INTEGER      REFERENCES users(id) ON DELETE SET NULL,
    attachment  VARCHAR(255),
    status      VARCHAR(20)  NOT NULL DEFAULT 'open'
                CHECK (status IN ('open', 'closed'))
);

-- =============================================================
--  report_messages
-- =============================================================
CREATE TABLE report_messages (
    id          SERIAL      PRIMARY KEY,
    report_id   INTEGER     NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    user_id     INTEGER     NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
    message     TEXT        NOT NULL,
    attachment  VARCHAR(255),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- =============================================================
--  Indexes
-- =============================================================
CREATE INDEX idx_reports_user        ON reports         (user_id);
CREATE INDEX idx_reports_category    ON reports         (category_id);
CREATE INDEX idx_messages_report     ON report_messages (report_id);
CREATE INDEX idx_messages_created_at ON report_messages (created_at);

-- =============================================================
--  Seed data – default categories
-- =============================================================
INSERT INTO categories (name) VALUES
    ('Šikana / obtěžování'),
    ('Vandalismus'),
    ('Bezpečnostní incident'),
    ('Technická závada'),
    ('Problém s učitelem'),
    ('Jiný problém');

-- =============================================================
--  Seed data – default admin account
--
--  Username : admin
--  Password : Admin1234
--
--  !! CHANGE THE PASSWORD IMMEDIATELY AFTER FIRST LOGIN !!
--
--  Hash generated with Nette Passwords (bcrypt, cost=10).
--  If you need a different password, generate the hash with:
--    php -r "echo (new Nette\Security\Passwords)->hash('yourpassword');"
-- =============================================================
INSERT INTO users (username, password_hash, role) VALUES (
    'admin',
    '$2y$10$7EqJtq98hPqEX7fNZaFWoOe1ErkVyq/4lHKDEn3OKZV31fNMjkbam',
    'admin'
);
