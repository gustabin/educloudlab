-- M11b: observability (platform tables, not tenant-owned; visible to platform admins only).

-- One row per minute x route name x method x status class. Route names come from the registry (never URLs, so no
-- ids); latency histogram buckets are non-cumulative counts: le_50 = [0, 50] ms, le_100 = (50, 100] ms, ...
CREATE TABLE request_metrics (
    bucket_start  DATETIME          NOT NULL,
    route         VARCHAR(80)       NOT NULL,
    method        VARCHAR(7)        NOT NULL,
    status_class  TINYINT UNSIGNED  NOT NULL,
    requests      INT UNSIGNED      NOT NULL DEFAULT 0,
    total_ms      BIGINT UNSIGNED   NOT NULL DEFAULT 0,
    max_ms        INT UNSIGNED      NOT NULL DEFAULT 0,
    le_50         INT UNSIGNED      NOT NULL DEFAULT 0,
    le_100        INT UNSIGNED      NOT NULL DEFAULT 0,
    le_250        INT UNSIGNED      NOT NULL DEFAULT 0,
    le_500        INT UNSIGNED      NOT NULL DEFAULT 0,
    le_1000       INT UNSIGNED      NOT NULL DEFAULT 0,
    le_2500       INT UNSIGNED      NOT NULL DEFAULT 0,
    le_5000       INT UNSIGNED      NOT NULL DEFAULT 0,
    le_inf        INT UNSIGNED      NOT NULL DEFAULT 0,
    created_at    DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (bucket_start, route, method, status_class),
    CONSTRAINT ck_request_metrics_class CHECK (status_class BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liveness of background processes. details: allowlisted counts/booleans only (no paths, no errors).
CREATE TABLE component_heartbeats (
    component     VARCHAR(40)  NOT NULL,
    instance      VARCHAR(80)  NOT NULL,
    started_at    DATETIME(3)  NOT NULL,
    last_seen_at  DATETIME(3)  NOT NULL,
    details       JSON         NULL,
    created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (component),
    CONSTRAINT ck_component_heartbeats_details CHECK (details IS NULL OR json_valid(details))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
