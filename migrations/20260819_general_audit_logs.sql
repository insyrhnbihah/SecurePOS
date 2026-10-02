CREATE TABLE IF NOT EXISTS audit_logs (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    actor_name VARCHAR(100) NULL,
    actor_role VARCHAR(50) NULL,
    module VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    result VARCHAR(20) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_user (user_id),
    KEY idx_audit_logs_created_at (created_at),
    KEY idx_audit_logs_module_action (module, action),
    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
