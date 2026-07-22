SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
CREATE DATABASE IF NOT EXISTS school_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE school_library;

DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS borrow_transactions;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS categories;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    class_name VARCHAR(50) DEFAULT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    -- No default administrator is seeded. Register normally, then promote explicitly:
    -- UPDATE users SET role='admin' WHERE email='admin@example.com';
    role ENUM('student','librarian','admin') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE books (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL preserves legacy school-library inventory',
    title VARCHAR(255) NOT NULL,
    author VARCHAR(180) NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    cover_image VARCHAR(255) DEFAULT NULL,
    total_copies INT UNSIGNED NOT NULL DEFAULT 1,
    available_copies INT UNSIGNED NOT NULL DEFAULT 1,
    price DECIMAL(12,2) DEFAULT NULL,
    rental_price DECIMAL(12,2) DEFAULT NULL,
    listing_type ENUM('sale','rental','both') NOT NULL DEFAULT 'rental',
    address VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_books_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_books_category FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_books_total_copies CHECK (total_copies >= 1),
    CONSTRAINT chk_books_available_copies CHECK (available_copies <= total_copies),
    INDEX idx_books_owner (user_id, is_active),
    INDEX idx_books_public (is_active, created_at), INDEX idx_books_title (title), INDEX idx_books_author (author), INDEX idx_books_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED DEFAULT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    class_name VARCHAR(50) NOT NULL,
    phone VARCHAR(100) DEFAULT NULL,
    student_code VARCHAR(50) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_students_person_name (last_name, first_name), INDEX idx_students_class (class_name), INDEX idx_students_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE borrow_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    borrow_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE DEFAULT NULL,
    status ENUM('borrowed','returned','overdue') NOT NULL DEFAULT 'borrowed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transactions_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_student FOREIGN KEY (student_id) REFERENCES students(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_transactions_due_date CHECK (due_date >= borrow_date),
    CONSTRAINT chk_transactions_return_date CHECK (return_date IS NULL OR return_date >= borrow_date),
    INDEX idx_transactions_status_due (status, due_date), INDEX idx_transactions_book (book_id), INDEX idx_transactions_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    request_date DATE NOT NULL,
    pickup_date DATE NOT NULL,
    status ENUM('pending','approved','ready','collected','cancelled','expired') NOT NULL DEFAULT 'pending',
    active_request TINYINT GENERATED ALWAYS AS (CASE WHEN status IN ('pending','approved','ready') THEN 1 ELSE NULL END) STORED,
    approved_at DATETIME DEFAULT NULL, ready_at DATETIME DEFAULT NULL, collected_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL,
    borrow_transaction_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reservations_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservations_student FOREIGN KEY (student_id) REFERENCES students(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservations_transaction FOREIGN KEY (borrow_transaction_id) REFERENCES borrow_transactions(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_reservations_book_status (book_id, status), INDEX idx_reservations_student_status (student_id, status), INDEX idx_reservations_pickup_status (pickup_date, status),
    UNIQUE INDEX uq_reservations_active_request (book_id, student_id, active_request)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    student_name VARCHAR(180) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    INDEX idx_reviews_book_created (book_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categories (id,name) VALUES (1,'Badiiy adabiyot'),(2,'Aniq fanlar'),(3,'Tarix'),(4,'Axborot texnologiyalari');
INSERT INTO books (id,user_id,title,author,category_id,description,cover_image,total_copies,available_copies,listing_type,created_at) VALUES
(1,NULL,'O‘tkan kunlar','Abdulla Qodiriy',1,'O‘zbek romanchiligining ilk yirik namunasi.',NULL,5,3,'rental',CURRENT_TIMESTAMP-INTERVAL 90 DAY),
(2,NULL,'Algebra asoslari','M. Usmonov',2,'Maktab o‘quvchilari uchun algebra qo‘llanmasi.',NULL,4,3,'rental',CURRENT_TIMESTAMP-INTERVAL 70 DAY),
(3,NULL,'Temur tuzuklari','Amir Temur',3,'Davlat boshqaruvi va adolat tamoyillariga bag‘ishlangan tarixiy asar.',NULL,3,3,'rental',CURRENT_TIMESTAMP-INTERVAL 50 DAY),
(4,NULL,'Dasturlashga kirish','S. Rahmonov',4,'Algoritmlar va dasturlash asoslari bo‘yicha boshlang‘ich qo‘llanma.',NULL,2,2,'rental',CURRENT_TIMESTAMP-INTERVAL 30 DAY);
INSERT INTO students (id,user_id,first_name,last_name,full_name,class_name,phone,student_code) VALUES
(1,NULL,'Aziza','Karimova','Aziza Karimova','9-A','+998 90 123 45 67','ST-2026-001'),
(2,NULL,'Javohir','Aliyev','Javohir Aliyev','10-B','+998 91 234 56 78','ST-2026-002'),
(3,NULL,'Malika','Ergasheva','Malika Ergasheva','8-A','+998 93 345 67 89','ST-2026-003'),
(4,NULL,'Sardor','Hamidov','Sardor Hamidov','11-V','+998 95 456 78 90','ST-2026-004');
INSERT INTO borrow_transactions (id,book_id,student_id,borrow_date,due_date,return_date,status) VALUES
(1,1,1,CURDATE()-INTERVAL 3 DAY,CURDATE()+INTERVAL 11 DAY,NULL,'borrowed'),
(2,1,2,CURDATE()-INTERVAL 30 DAY,CURDATE()-INTERVAL 16 DAY,NULL,'overdue'),
(3,2,3,CURDATE()-INTERVAL 5 DAY,CURDATE()+INTERVAL 9 DAY,NULL,'borrowed'),
(4,3,4,CURDATE()-INTERVAL 40 DAY,CURDATE()-INTERVAL 26 DAY,CURDATE()-INTERVAL 28 DAY,'returned');
INSERT INTO reviews (id,book_id,student_name,rating,comment,created_at) VALUES
(1,1,'Dilnoza Sattorova',5,'Juda ta’sirli va mazmunli roman.',CURRENT_TIMESTAMP-INTERVAL 12 DAY),
(2,1,'Bekzod Mahmudov',4,'Asar tili chiroyli, voqealar rivoji qiziqarli.',CURRENT_TIMESTAMP-INTERVAL 8 DAY),
(3,2,'Shahnoza Qodirova',5,'Misollar sodda va tushunarli.',CURRENT_TIMESTAMP-INTERVAL 5 DAY),
(4,4,'Umid To‘xtayev',4,'Boshlovchilar uchun foydali qo‘llanma.',CURRENT_TIMESTAMP-INTERVAL 2 DAY);
SET FOREIGN_KEY_CHECKS = 1;
