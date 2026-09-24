CREATE DATABASE IF NOT EXISTS barber_company CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barber_company;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','vendor','customer') NOT NULL DEFAULT 'customer',
    status ENUM('pending','active','inactive','rejected') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB;

CREATE TABLE salons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    description TEXT NULL,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) NULL,
    pin_code VARCHAR(12) NULL,
    phone VARCHAR(30) NULL,
    image_url VARCHAR(255) NULL,
    opening_time TIME NOT NULL DEFAULT '09:00:00',
    closing_time TIME NOT NULL DEFAULT '20:00:00',
    rating DECIMAL(2,1) NOT NULL DEFAULT 0,
    status ENUM('pending','approved','rejected','inactive') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_salons_discovery (status, city)
) ENGINE=InnoDB;

CREATE TABLE service_categories (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE);
CREATE TABLE services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    image_url VARCHAR(255) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (salon_id) REFERENCES salons(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE SET NULL,
    INDEX idx_services_salon (salon_id, status)
) ENGINE=InnoDB;

CREATE TABLE staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    gender VARCHAR(30) NULL,
    phone VARCHAR(30) NULL,
    experience_years DECIMAL(3,1) DEFAULT 0,
    specialization VARCHAR(180) NULL,
    bio TEXT NULL,
    image_url VARCHAR(255) NULL,
    joining_date DATE NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    FOREIGN KEY (salon_id) REFERENCES salons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE staff_services (
    staff_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (staff_id, service_id),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE appointments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_code VARCHAR(24) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    appointment_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','upi','card') NOT NULL DEFAULT 'cash',
    status ENUM('pending','confirmed','completed','cancelled','rescheduled','rejected') NOT NULL DEFAULT 'pending',
    payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id), FOREIGN KEY (salon_id) REFERENCES salons(id),
    FOREIGN KEY (service_id) REFERENCES services(id), FOREIGN KEY (staff_id) REFERENCES staff(id),
    UNIQUE KEY unique_staff_slot (staff_id, appointment_date, start_time),
    INDEX idx_appointments_salon_date (salon_id, appointment_date, status),
    INDEX idx_appointments_customer (customer_id, appointment_date)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, appointment_id INT UNSIGNED NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL, vendor_id INT UNSIGNED NOT NULL, amount DECIMAL(10,2) NOT NULL,
    method ENUM('cash','upi','card') NOT NULL, transaction_reference VARCHAR(100) NULL,
    status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending', paid_at DATETIME NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id), FOREIGN KEY (vendor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, appointment_id INT UNSIGNED NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL, salon_id INT UNSIGNED NOT NULL, staff_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL, service_rating TINYINT UNSIGNED NOT NULL, staff_rating TINYINT UNSIGNED NOT NULL,
    review TEXT NULL, status ENUM('visible','hidden') NOT NULL DEFAULT 'visible', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE, FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (salon_id) REFERENCES salons(id), FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, title VARCHAR(160) NOT NULL, message TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE salon_gallery (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, salon_id INT UNSIGNED NOT NULL, image_url VARCHAR(255) NOT NULL, FOREIGN KEY (salon_id) REFERENCES salons(id) ON DELETE CASCADE);
CREATE TABLE favorites (customer_id INT UNSIGNED NOT NULL, salon_id INT UNSIGNED NOT NULL, PRIMARY KEY(customer_id, salon_id), FOREIGN KEY(customer_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(salon_id) REFERENCES salons(id) ON DELETE CASCADE);

INSERT INTO service_categories (name) VALUES ('Hair'), ('Beard'), ('Skin & Care'), ('Wellness');
INSERT INTO users (name, email, phone, password_hash, role, status) VALUES
('Platform Admin', 'admin@thebarbercompany.test', '9000000000', '$2y$10$KeaE/PI5hq0e564ntnVxBee9wkD1kIGywIBndKi1WvzQirVClcxye', 'admin', 'active'),
('Arjun Mehta', 'vendor@thebarbercompany.test', '9000000001', '$2y$10$KeaE/PI5hq0e564ntnVxBee9wkD1kIGywIBndKi1WvzQirVClcxye', 'vendor', 'active'),
('Demo Customer', 'customer@thebarbercompany.test', '9000000002', '$2y$10$KeaE/PI5hq0e564ntnVxBee9wkD1kIGywIBndKi1WvzQirVClcxye', 'customer', 'active');
INSERT INTO salons (vendor_id, name, slug, description, address, city, state, pin_code, phone, image_url, opening_time, closing_time, rating, status) VALUES
(2, 'The Groom Room', 'the-groom-room', 'A considered grooming studio for sharp cuts, clean shaves, and slow Sunday rituals.', '24 Park Street', 'Mumbai', 'Maharashtra', '400001', '9000000001', 'https://images.unsplash.com/photo-1585747860715-2ba37e788b70?auto=format&fit=crop&w=1200&q=85', '09:00:00', '21:00:00', 4.8, 'approved');
INSERT INTO services (salon_id, category_id, name, description, price, duration_minutes) VALUES
(1,1,'Signature Haircut','A tailored cut finished with hot towel styling.',450,45),(1,2,'Classic Beard Sculpt','Shape, line-up, and a warm towel finish.',280,30),(1,3,'Deep Clean Facial','A reset for tired city skin.',700,60),(1,4,'Head & Shoulder Massage','Thirty minutes of quiet, focused relief.',550,30);
INSERT INTO staff (salon_id, name, gender, experience_years, specialization, bio, status) VALUES
(1,'Rahul Kumar','Male',8,'Precision cuts & beard design','Eight years of making everyday grooming feel ceremonial.','active'),
(1,'Aman Sharma','Male',5,'Colour, styling & treatments','Detail-led styling for events, workdays, and everything between.','active');
INSERT INTO staff_services (staff_id, service_id) VALUES (1,1),(1,2),(1,4),(2,1),(2,3);
