USE school_library;

SET @database_name := DATABASE();

SET @has_is_active := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'books'
      AND COLUMN_NAME = 'is_active'
);

SET @add_is_active := IF(
    @has_is_active = 0,
    'ALTER TABLE books ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER available_copies',
    'SELECT 1'
);

PREPARE library_statement FROM @add_is_active;
EXECUTE library_statement;
DEALLOCATE PREPARE library_statement;

CREATE TABLE IF NOT EXISTS reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    request_date DATE NOT NULL,
    pickup_date DATE NOT NULL,
    status ENUM(
        'pending',
        'approved',
        'fulfilled',
        'cancelled',
        'expired'
    ) NOT NULL DEFAULT 'pending',
    approved_at DATETIME DEFAULT NULL,
    fulfilled_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_reservations_book
        FOREIGN KEY (book_id)
        REFERENCES books(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_reservations_student
        FOREIGN KEY (student_id)
        REFERENCES students(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_reservations_book_status (book_id, status),
    INDEX idx_reservations_student_status (student_id, status),
    INDEX idx_reservations_pickup_status (pickup_date, status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

UPDATE books
SET is_active = 1
WHERE is_active IS NULL;
