<?php

declare(strict_types=1);

/**
 * Database migrations. Each entry is applied exactly once, in order, and
 * recorded in the `migrations` table. Never change an applied migration;
 * append a new one instead.
 */
return [
    '001_initial' => <<<SQL
        CREATE TABLE jobs (
            id BIGSERIAL PRIMARY KEY,
            pid INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'running', -- running | finished | failed | aborted
            started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            finished_at TIMESTAMPTZ,
            error TEXT
        );

        CREATE TABLE checks (
            id BIGSERIAL PRIMARY KEY,
            job_id BIGINT REFERENCES jobs (id) ON DELETE SET NULL,
            site TEXT NOT NULL,
            checked_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            success BOOLEAN NOT NULL,
            slow BOOLEAN NOT NULL DEFAULT false, -- response time exceeded max_response_time
            status_code INTEGER,
            response_time DOUBLE PRECISION, -- seconds
            error TEXT
        );
        CREATE INDEX checks_site_checked_at_idx ON checks (site, checked_at DESC);
        CREATE INDEX checks_checked_at_idx ON checks (checked_at);

        CREATE TABLE notifications (
            id BIGSERIAL PRIMARY KEY,
            sent_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            down_sites JSONB NOT NULL DEFAULT '[]', -- sites considered down at the time of sending
            subject TEXT NOT NULL,
            recipients TEXT NOT NULL
        );
        SQL,
];
