CREATE TABLE IF NOT EXISTS attendance_kiosk_pairings (
    id INT NOT NULL AUTO_INCREMENT,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by_user_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_kiosk_pairing_hash (code_hash),
    KEY idx_attendance_kiosk_pairing_expiry (expires_at),
    CONSTRAINT fk_attendance_kiosk_pairing_user
        FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_kiosk_sessions (
    id INT NOT NULL AUTO_INCREMENT,
    credential_hash CHAR(64) NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Attendance display',
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by_user_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_kiosk_credential_hash (credential_hash),
    KEY idx_attendance_kiosk_session_status (expires_at, revoked_at),
    CONSTRAINT fk_attendance_kiosk_session_user
        FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_attendance_logs_employee_token_result
    ON attendance_attempt_logs (employee_id, qr_token_id, result, attempt_type);
