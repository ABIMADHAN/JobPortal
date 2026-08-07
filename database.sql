-- ============================================================
-- Job & Internship Portal - Database Schema
-- MySQL 8+
-- ============================================================
--
-- ER OVERVIEW (text diagram)
--
-- users (1) ────< student_profiles (1)
-- users (1) ────< companies (1)
-- companies (1) ────< jobs (many)
-- users[student] (1) ────< applications (many) >──── jobs (1)
-- users (1) ────< password_resets (many)
--
-- users
--   PK: id
--   role: 'student' | 'recruiter'
--
-- student_profiles
--   PK: id   FK: user_id -> users.id (1:1)
--
-- companies
--   PK: id   FK: user_id -> users.id (1:1, recruiter's company)
--
-- jobs
--   PK: id   FK: company_id -> companies.id
--
-- applications
--   PK: id   FK: job_id -> jobs.id, student_id -> users.id
--   UNIQUE(job_id, student_id) -- one application per student per job
--
-- password_resets
--   PK: id   FK: user_id -> users.id
-- ============================================================

CREATE DATABASE IF NOT EXISTS job_portal
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE job_portal;

-- ------------------------------------------------------------
-- Table: users
-- ------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role            ENUM('student', 'recruiter') NOT NULL,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    phone           VARCHAR(20)  DEFAULT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Table: student_profiles (1:1 with users where role = student)
-- ------------------------------------------------------------
CREATE TABLE student_profiles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    education       VARCHAR(255) DEFAULT NULL,
    skills          TEXT         DEFAULT NULL,
    bio             TEXT         DEFAULT NULL,
    resume_path     VARCHAR(255) DEFAULT NULL,
    resume_original_name VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_profiles_user (user_id),
    CONSTRAINT fk_student_profiles_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Table: companies (1:1 with users where role = recruiter)
-- ------------------------------------------------------------
CREATE TABLE companies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    company_name    VARCHAR(150) NOT NULL,
    description     TEXT DEFAULT NULL,
    website         VARCHAR(255) DEFAULT NULL,
    location        VARCHAR(150) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_companies_user (user_id),
    CONSTRAINT fk_companies_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Table: jobs
-- ------------------------------------------------------------
CREATE TABLE jobs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id          INT UNSIGNED NOT NULL,
    title               VARCHAR(150) NOT NULL,
    description         TEXT NOT NULL,
    requirements        TEXT DEFAULT NULL,
    skills_required     VARCHAR(500) DEFAULT NULL,
    salary              VARCHAR(100) DEFAULT NULL,
    job_type            ENUM('full-time','part-time','internship','contract') NOT NULL,
    location            VARCHAR(150) DEFAULT NULL,
    work_mode           ENUM('remote','hybrid','onsite') NOT NULL DEFAULT 'onsite',
    vacancy_count       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    deadline            DATE DEFAULT NULL,
    status              ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_jobs_company
        FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE,
    KEY idx_jobs_title (title),
    KEY idx_jobs_location (location),
    KEY idx_jobs_job_type (job_type),
    KEY idx_jobs_status (status),
    KEY idx_jobs_company (company_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Table: applications
-- ------------------------------------------------------------
CREATE TABLE applications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id          INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    status          ENUM('applied','under_review','shortlisted','rejected','hired','withdrawn')
                        NOT NULL DEFAULT 'applied',
    -- Interview slot set by the recruiter; drives the "Upcoming Interviews" widget.
    interview_at    DATETIME DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_application_job_student (job_id, student_id),
    CONSTRAINT fk_applications_job
        FOREIGN KEY (job_id) REFERENCES jobs(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_applications_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE,
    KEY idx_applications_status (status),
    KEY idx_applications_student (student_id),
    KEY idx_applications_job (job_id),
    KEY idx_applications_interview (interview_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Table: saved_jobs  (a student's wishlist / bookmarked jobs)
-- ------------------------------------------------------------
CREATE TABLE saved_jobs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED NOT NULL,
    job_id          INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_saved_jobs_student_job (student_id, job_id),
    CONSTRAINT fk_saved_jobs_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_saved_jobs_job
        FOREIGN KEY (job_id) REFERENCES jobs(id)
        ON DELETE CASCADE,
    KEY idx_saved_jobs_student (student_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Table: password_resets (optional feature)
-- ------------------------------------------------------------
CREATE TABLE password_resets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used            TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    KEY idx_password_resets_token (token_hash)
) ENGINE=InnoDB;
