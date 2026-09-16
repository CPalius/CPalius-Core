/*
    Private messaging schema.

    Kept aligned with the Doctrine mapping on the entities in Entity/: same
    column types, same index names, same foreign-key names. ModuleInstallContext
    splits this file on ";" and DISCARDS any statement starting with "--", so
    comments are block comments on purpose.

    Foreign keys are declared inline rather than through ALTER TABLE so that
    CREATE TABLE IF NOT EXISTS makes the whole file re-runnable.

    Every table is module-owned and dropped by ModuleInstaller::uninstall().
*/
CREATE TABLE IF NOT EXISTS cp_message_threads (
    id INT AUTO_INCREMENT NOT NULL,
    public_id VARCHAR(16) NOT NULL,
    pair_key VARCHAR(96) NOT NULL,
    subject VARCHAR(191) DEFAULT NULL,
    context_type VARCHAR(64) DEFAULT NULL,
    context_id INT DEFAULT NULL,
    context_label VARCHAR(191) DEFAULT NULL,
    context_url VARCHAR(500) DEFAULT NULL,
    created_by_id INT DEFAULT NULL,
    last_message_at DATETIME NOT NULL,
    message_count INT NOT NULL,
    closed TINYINT(1) NOT NULL,
    closed_at DATETIME DEFAULT NULL,
    closed_by_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_message_thread_public (public_id),
    UNIQUE INDEX uniq_message_thread_pair (pair_key),
    INDEX idx_message_thread_last (last_message_at),
    INDEX idx_message_thread_context (context_type, context_id),
    INDEX IDX_CP_MSG_THREAD_CREATED_BY (created_by_id),
    INDEX IDX_CP_MSG_THREAD_CLOSED_BY (closed_by_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_CP_MSG_THREAD_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES cp_users (id) ON DELETE SET NULL,
    CONSTRAINT FK_CP_MSG_THREAD_CLOSED_BY FOREIGN KEY (closed_by_id) REFERENCES cp_users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_message_participants (
    id INT AUTO_INCREMENT NOT NULL,
    thread_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read_at DATETIME DEFAULT NULL,
    unread_count INT NOT NULL,
    archived TINYINT(1) NOT NULL,
    muted TINYINT(1) NOT NULL,
    hidden TINYINT(1) NOT NULL,
    joined_at DATETIME NOT NULL,
    INDEX IDX_CP_MSG_PART_THREAD (thread_id),
    INDEX IDX_CP_MSG_PART_USER (user_id),
    INDEX idx_message_participant_inbox (user_id, hidden, archived, last_read_at),
    UNIQUE INDEX uniq_message_participant (thread_id, user_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_CP_MSG_PART_THREAD FOREIGN KEY (thread_id) REFERENCES cp_message_threads (id) ON DELETE CASCADE,
    CONSTRAINT FK_CP_MSG_PART_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_messages (
    id INT AUTO_INCREMENT NOT NULL,
    thread_id INT NOT NULL,
    author_id INT DEFAULT NULL,
    body LONGTEXT NOT NULL,
    body_format VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    edited_at DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    deleted_by_id INT DEFAULT NULL,
    INDEX IDX_CP_MSG_THREAD (thread_id),
    INDEX IDX_CP_MSG_AUTHOR (author_id),
    INDEX IDX_CP_MSG_DELETED_BY (deleted_by_id),
    INDEX idx_message_thread_created (thread_id, created_at),
    PRIMARY KEY (id),
    CONSTRAINT FK_CP_MSG_THREAD FOREIGN KEY (thread_id) REFERENCES cp_message_threads (id) ON DELETE CASCADE,
    CONSTRAINT FK_CP_MSG_AUTHOR FOREIGN KEY (author_id) REFERENCES cp_users (id) ON DELETE SET NULL,
    CONSTRAINT FK_CP_MSG_DELETED_BY FOREIGN KEY (deleted_by_id) REFERENCES cp_users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_message_blocks (
    id INT AUTO_INCREMENT NOT NULL,
    blocker_id INT NOT NULL,
    blocked_id INT NOT NULL,
    reason VARCHAR(191) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX IDX_CP_MSG_BLOCK_BLOCKER (blocker_id),
    INDEX IDX_CP_MSG_BLOCK_BLOCKED (blocked_id),
    UNIQUE INDEX uniq_message_block (blocker_id, blocked_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_CP_MSG_BLOCK_BLOCKER FOREIGN KEY (blocker_id) REFERENCES cp_users (id) ON DELETE CASCADE,
    CONSTRAINT FK_CP_MSG_BLOCK_BLOCKED FOREIGN KEY (blocked_id) REFERENCES cp_users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_message_reports (
    id INT AUTO_INCREMENT NOT NULL,
    message_id INT NOT NULL,
    reporter_id INT DEFAULT NULL,
    reason VARCHAR(32) NOT NULL,
    details VARCHAR(1000) DEFAULT NULL,
    status VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    resolved_at DATETIME DEFAULT NULL,
    resolved_by_id INT DEFAULT NULL,
    resolution_note VARCHAR(500) DEFAULT NULL,
    INDEX IDX_CP_MSG_REPORT_MESSAGE (message_id),
    INDEX IDX_CP_MSG_REPORT_REPORTER (reporter_id),
    INDEX IDX_CP_MSG_REPORT_RESOLVER (resolved_by_id),
    INDEX idx_message_report_status (status, created_at),
    UNIQUE INDEX uniq_message_report_once (message_id, reporter_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_CP_MSG_REPORT_MESSAGE FOREIGN KEY (message_id) REFERENCES cp_messages (id) ON DELETE CASCADE,
    CONSTRAINT FK_CP_MSG_REPORT_REPORTER FOREIGN KEY (reporter_id) REFERENCES cp_users (id) ON DELETE SET NULL,
    CONSTRAINT FK_CP_MSG_REPORT_RESOLVER FOREIGN KEY (resolved_by_id) REFERENCES cp_users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS cp_message_restrictions (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_by_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    UNIQUE INDEX uniq_message_restriction_user (user_id),
    INDEX IDX_CP_MSG_RESTRICT_BY (created_by_id),
    INDEX idx_message_restriction_expires (expires_at),
    PRIMARY KEY (id),
    CONSTRAINT FK_CP_MSG_RESTRICT_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE,
    CONSTRAINT FK_CP_MSG_RESTRICT_BY FOREIGN KEY (created_by_id) REFERENCES cp_users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
