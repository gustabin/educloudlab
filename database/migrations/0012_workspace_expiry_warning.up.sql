-- M11a: remember that the owner of an expiring lab workspace was warned (reset when activity extends the expiry).

ALTER TABLE workspaces
    ADD COLUMN expiry_warned_at DATETIME(3) NULL AFTER expires_at;
