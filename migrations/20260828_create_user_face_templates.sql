-- SecurePOS Face Verification Step 22: one encrypted face template per user.
CREATE TABLE user_face_templates (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    descriptor_ciphertext MEDIUMBLOB NOT NULL,
    encryption_iv VARBINARY(12) NOT NULL,
    authentication_tag VARBINARY(16) NOT NULL,
    model_version VARCHAR(50) NOT NULL,
    enrolled_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_face_templates_user (user_id),
    KEY idx_user_face_templates_enrolled_by (enrolled_by_user_id),
    CONSTRAINT fk_user_face_templates_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_face_templates_enrolled_by FOREIGN KEY (enrolled_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
