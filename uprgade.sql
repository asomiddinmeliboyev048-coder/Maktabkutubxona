-- Existing-install migration for the reservation and inventory upgrade.
-- Designed to be repeatable on the original project schema and on partially upgraded installs.
USE school_library;
SET NAMES utf8mb4;

DELIMITER $$
DROP PROCEDURE IF EXISTS library_add_column_if_missing$$
CREATE PROCEDURE library_add_column_if_missing(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', table_name_value,
            '` ADD COLUMN `', column_name_value, '` ', definition_value
        );
        PREPARE statement_to_run FROM @ddl;
        EXECUTE statement_to_run;
        DEALLOCATE PREPARE statement_to_run;
    END IF;
END$$

DROP PROCEDURE IF EXISTS library_drop_column_if_present$$
CREATE PROCEDURE library_drop_column_if_present(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', table_name_value,
            '` DROP COLUMN `', column_name_value, '`'
        );
        PREPARE statement_to_run FROM @ddl;
        EXECUTE statement_to_run;
        DEALLOCATE PREPARE statement_to_run;
    END IF;
END$$

DROP PROCEDURE IF EXISTS library_add_index_if_missing$$
CREATE PROCEDURE library_add_index_if_missing(
    IN table_name_value VARCHAR(64),
    IN index_name_value VARCHAR(64),
    IN definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND INDEX_NAME = index_name_value
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', table_name_value,
            '` ADD INDEX `', index_name_value, '` ', definition_value
        );
        PREPARE statement_to_run FROM @ddl;
        EXECUTE statement_to_run;
        DEALLOCATE PREPARE statement_to_run;
    END IF;
END$$

DROP PROCEDURE IF EXISTS library_add_unique_index_if_missing$$
CREATE PROCEDURE library_add_unique_index_if_missing(
    IN table_name_value VARCHAR(64),
    IN index_name_value VARCHAR(64),
    IN definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND INDEX_NAME = index_name_value
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', table_name_value,
            '` ADD UNIQUE INDEX `', index_name_value, '` ', definition_value
        );
        PREPARE statement_to_run FROM @ddl;
        EXECUTE statement_to_run;
        DEALLOCATE PREPARE statement_to_run;
    END IF;
END$$

DROP PROCEDURE IF EXISTS library_add_fk_if_missing$$
CREATE PROCEDURE library_add_fk_if_missing(
    IN table_name_value VARCHAR(64),
    IN constraint_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN referenced_table_value VARCHAR(64),
    IN referenced_column_value VARCHAR(64),
    IN delete_rule_value VARCHAR(16)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND CONSTRAINT_NAME = constraint_name_value
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', table_name_value,
            '` ADD CONSTRAINT `', constraint_name_value,
            '` FOREIGN KEY (`', column_name_value,
            '`) REFERENCES `', referenced_table_value,
            '` (`', referenced_column_value,
            '`) ON UPDATE CASCADE ON DELETE ', delete_rule_value
        );
        PREPARE statement_to_run FROM @ddl;
        EXECUTE statement_to_run;
        DEALLOCATE PREPARE statement_to_run;
    END IF;
END$$
DELIMITER ;

-- Books: public visibility and editable timestamps.
CALL library_add_column_if_missing(
    'books', 'is_active',
    'TINYINT(1) NOT NULL DEFAULT 1 AFTER `available_copies`'
);
CALL library_add_column_if_missing(
    'books', 'updated_at',
    'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'
);
UPDATE books SET is_active = 1 WHERE is_active IS NULL;
CALL library_add_index_if_missing('books', 'idx_books_public', '(`is_active`, `created_at`)');

-- Students/subscribers: split names, contact, lifecycle, and timestamps.
CALL library_add_column_if_missing('students', 'first_name', 'VARCHAR(80) NULL AFTER `id`');
CALL library_add_column_if_missing('students', 'last_name', 'VARCHAR(80) NULL AFTER `first_name`');
CALL library_add_column_if_missing('students', 'phone', 'VARCHAR(100) NULL AFTER `class_name`');
CALL library_add_column_if_missing('students', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `student_code`');
CALL library_add_column_if_missing('students', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_active`');
CALL library_add_column_if_missing('students', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
ALTER TABLE students MODIFY phone VARCHAR(100) NULL;
UPDATE students
SET first_name = COALESCE(NULLIF(TRIM(first_name), ''), SUBSTRING_INDEX(TRIM(full_name), ' ', 1)),
    last_name = COALESCE(
        NULLIF(TRIM(last_name), ''),
        NULLIF(TRIM(SUBSTRING(TRIM(full_name), LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)) + 1)), ''),
        '—'
    ),
    is_active = COALESCE(is_active, 1);
ALTER TABLE students
    MODIFY first_name VARCHAR(80) NOT NULL,
    MODIFY last_name VARCHAR(80) NOT NULL;
CALL library_add_index_if_missing('students', 'idx_students_active', '(`is_active`)');
CALL library_add_index_if_missing('students', 'idx_students_person_name', '(`last_name`, `first_name`)');

SET @legacy_name_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'students'
      AND INDEX_NAME = 'idx_students_name'
);
SET @drop_legacy_name_index = IF(
    @legacy_name_index > 0,
    'ALTER TABLE `students` DROP INDEX `idx_students_name`',
    'SELECT 1'
);
PREPARE statement_to_run FROM @drop_legacy_name_index;
EXECUTE statement_to_run;
DEALLOCATE PREPARE statement_to_run;

-- Loans: shared activity ordering/index support.
CALL library_add_column_if_missing(
    'borrow_transactions', 'created_at',
    'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
);
CALL library_add_index_if_missing(
    'borrow_transactions', 'idx_transactions_status_due',
    '(`status`, `due_date`)'
);

-- Freshly create reservations when the interrupted upgrade never created it.
CREATE TABLE IF NOT EXISTS reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    request_date DATE NOT NULL,
    pickup_date DATE NOT NULL,
    status ENUM('pending','approved','ready','collected','cancelled','expired') NOT NULL DEFAULT 'pending',
    active_request TINYINT GENERATED ALWAYS AS (
        CASE WHEN status IN ('pending','approved','ready') THEN 1 ELSE NULL END
    ) STORED,
    approved_at DATETIME DEFAULT NULL,
    ready_at DATETIME DEFAULT NULL,
    collected_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    borrow_transaction_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reservations_book FOREIGN KEY (book_id)
        REFERENCES books(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservations_student FOREIGN KEY (student_id)
        REFERENCES students(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservations_transaction FOREIGN KEY (borrow_transaction_id)
        REFERENCES borrow_transactions(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_reservations_book_status (book_id, status),
    INDEX idx_reservations_student_status (student_id, status),
    INDEX idx_reservations_pickup_status (pickup_date, status),
    UNIQUE INDEX uq_reservations_active_request (book_id, student_id, active_request)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Complete partially created reservation tables one field at a time.
CALL library_add_column_if_missing('reservations', 'request_date', 'DATE NULL AFTER `student_id`');
CALL library_add_column_if_missing('reservations', 'pickup_date', 'DATE NULL AFTER `request_date`');
CALL library_add_column_if_missing('reservations', 'status', 'ENUM(''pending'',''approved'',''ready'',''collected'',''cancelled'',''expired'') NOT NULL DEFAULT ''pending'' AFTER `pickup_date`');
CALL library_add_column_if_missing('reservations', 'approved_at', 'DATETIME NULL AFTER `status`');
CALL library_add_column_if_missing('reservations', 'ready_at', 'DATETIME NULL AFTER `approved_at`');
CALL library_add_column_if_missing('reservations', 'collected_at', 'DATETIME NULL AFTER `ready_at`');
CALL library_add_column_if_missing('reservations', 'cancelled_at', 'DATETIME NULL AFTER `collected_at`');
CALL library_add_column_if_missing('reservations', 'expires_at', 'DATETIME NULL AFTER `cancelled_at`');
CALL library_add_column_if_missing('reservations', 'borrow_transaction_id', 'BIGINT UNSIGNED NULL AFTER `expires_at`');
CALL library_add_column_if_missing('reservations', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `borrow_transaction_id`');
CALL library_add_column_if_missing('reservations', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

UPDATE reservations
SET request_date = COALESCE(request_date, DATE(created_at), CURDATE()),
    pickup_date = COALESCE(pickup_date, request_date, DATE(created_at), CURDATE())
WHERE request_date IS NULL OR pickup_date IS NULL;
ALTER TABLE reservations
    MODIFY request_date DATE NOT NULL,
    MODIFY pickup_date DATE NOT NULL;

-- Keep the legacy fulfilled value temporarily, map it, then remove its old column safely.
ALTER TABLE reservations
    MODIFY status ENUM('pending','approved','ready','fulfilled','collected','cancelled','expired')
    NOT NULL DEFAULT 'pending';
SET @has_fulfilled_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'reservations'
      AND COLUMN_NAME = 'fulfilled_at'
);
SET @map_fulfilled_rows = IF(
    @has_fulfilled_column > 0,
    'UPDATE reservations SET status=''collected'', collected_at=COALESCE(collected_at, fulfilled_at) WHERE status=''fulfilled''',
    'UPDATE reservations SET status=''collected'', collected_at=COALESCE(collected_at, updated_at) WHERE status=''fulfilled'''
);
PREPARE statement_to_run FROM @map_fulfilled_rows;
EXECUTE statement_to_run;
DEALLOCATE PREPARE statement_to_run;
ALTER TABLE reservations
    MODIFY status ENUM('pending','approved','ready','collected','cancelled','expired')
    NOT NULL DEFAULT 'pending';
CALL library_drop_column_if_present('reservations', 'fulfilled_at');

UPDATE reservations
SET expires_at = TIMESTAMP(pickup_date, '23:59:59')
WHERE status IN ('pending','approved','ready') AND expires_at IS NULL;

-- If a partial upgrade allowed duplicates, retain the oldest active request and cancel later ones.
UPDATE reservations AS duplicate_request
INNER JOIN reservations AS earlier_request
        ON earlier_request.book_id = duplicate_request.book_id
       AND earlier_request.student_id = duplicate_request.student_id
       AND earlier_request.id < duplicate_request.id
       AND earlier_request.status IN ('pending','approved','ready')
SET duplicate_request.status = 'cancelled',
    duplicate_request.cancelled_at = COALESCE(duplicate_request.cancelled_at, NOW())
WHERE duplicate_request.status IN ('pending','approved','ready');

CALL library_add_column_if_missing(
    'reservations', 'active_request',
    'TINYINT GENERATED ALWAYS AS (CASE WHEN `status` IN (''pending'',''approved'',''ready'') THEN 1 ELSE NULL END) STORED AFTER `status`'
);
CALL library_add_index_if_missing('reservations', 'idx_reservations_book_status', '(`book_id`, `status`)');
CALL library_add_index_if_missing('reservations', 'idx_reservations_student_status', '(`student_id`, `status`)');
CALL library_add_index_if_missing('reservations', 'idx_reservations_pickup_status', '(`pickup_date`, `status`)');
CALL library_add_unique_index_if_missing(
    'reservations', 'uq_reservations_active_request',
    '(`book_id`, `student_id`, `active_request`)'
);

CALL library_add_fk_if_missing(
    'reservations', 'fk_reservations_book', 'book_id',
    'books', 'id', 'RESTRICT'
);
CALL library_add_fk_if_missing(
    'reservations', 'fk_reservations_student', 'student_id',
    'students', 'id', 'RESTRICT'
);
CALL library_add_fk_if_missing(
    'reservations', 'fk_reservations_transaction', 'borrow_transaction_id',
    'borrow_transactions', 'id', 'SET NULL'
);

DROP PROCEDURE IF EXISTS library_add_fk_if_missing;
DROP PROCEDURE IF EXISTS library_add_unique_index_if_missing;
DROP PROCEDURE IF EXISTS library_add_index_if_missing;
DROP PROCEDURE IF EXISTS library_drop_column_if_present;
DROP PROCEDURE IF EXISTS library_add_column_if_missing;
