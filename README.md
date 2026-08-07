# JobPortal — Job & Internship Portal

A Job & Internship Portal built with **plain procedural PHP 8 + MySQL**. No
framework, no MVC, no build step, no Composer — every page is a single PHP file
in the project root that renders its own HTML.

## Tech Stack

- **PHP 8+**, procedural style, PDO with prepared statements, PHP sessions
- **MySQL 8+**
- **CSS3** in one stylesheet — no frameworks, no JavaScript build
- A few inline `onsubmit="return confirm(...)"` handlers are the only JavaScript

## Features

- Two roles: **Student** and **Recruiter** (no admin)
- Student: profile + resume upload, browse/search/filter jobs, apply, withdraw,
  Kanban application tracker with a success-rate donut
- Recruiter: company profile, full job CRUD (open/close/delete), applicant review
  (shortlist / reject / hire / notes), resume download, dashboard stats
- Security: `password_hash`/`password_verify`, PDO prepared statements everywhere,
  session auth with role checks, CSRF tokens on every form, server-side validation,
  output escaping via `e()`, resume uploads validated by extension + MIME + size and
  stored under a randomized filename, POST-Redirect-GET so refreshes never resubmit

## Project Structure

Everything lives in the project root. `uploads/` is the **only** folder.

```
JobPortal/
├── config.php                 Environment loading + app constants
├── db.php                     PDO connection (get_db)
├── functions.php              Session, escaping, validation, flash, CSRF helpers
├── auth.php                   Login state, role guards, account creation
├── header.php                 Shared <head> + navbar + flash messages
├── footer.php                 Shared page footer
├── style.css                  The entire stylesheet
├── dashboard.png              Landing-page screenshot
│
├── index.php                  Landing page
├── jobs.php                   Job list, job detail, and "apply" POST
├── login.php                  Login form + authentication
├── logout.php                 Session teardown (POST only)
├── register-student.php       Student sign-up
├── register-recruiter.php     Recruiter sign-up
├── profile.php                Account / role profile / resume / password
├── student-dashboard.php      Kanban application tracker + withdraw
├── recruiter-dashboard.php    Stats, job CRUD, applicant review
├── download-resume.php        Streams a resume to the owning recruiter
│
├── uploads/                   Uploaded resumes (the only folder)
├── database.sql               Full schema: tables, keys, indexes
├── .env.example               Copy to .env and fill in your DB credentials
├── .htaccess                  Blocks .env/.sql/.md and the shared include files
└── .gitignore
```

### How a page is wired

Every page starts with `require_once __DIR__ . '/auth.php';`, which pulls in
`functions.php` → `db.php` → `config.php` and starts the session. Pages then:

1. Handle their own `POST` (validate → write → `flash()` → `redirect()`).
2. Query what they need for the `GET` render.
3. `require 'header.php'`, echo their HTML, `require 'footer.php'`.

There are no API endpoints and no `fetch()` calls — forms post straight to the
page that owns them.

## Local Setup (XAMPP)

1. **Put the project in your web root** — e.g. `C:\xampp\htdocs\JobPortal`.

2. **Start Apache and MySQL** from the XAMPP Control Panel.

3. **Create the database**

   ```bash
   mysql -u root -p < database.sql
   ```

   Or import `database.sql` through phpMyAdmin. This creates the `job_portal`
   database and all tables (`users`, `student_profiles`, `companies`, `jobs`,
   `applications`, `password_resets`).

4. **Environment**

   ```bash
   cp .env.example .env
   ```

   Edit `.env` with your MySQL credentials. XAMPP's defaults are usually
   `DB_USER=root` with an empty `DB_PASS`.

5. **Open the app** — <http://localhost/JobPortal/>

6. **Uploads folder** — make sure `uploads/` is writable by the web server.

### Without XAMPP

```bash
php -S localhost:8000
```

Then open <http://localhost:8000/>.

## Syntax Checking

```bash
for f in *.php; do php -l "$f"; done
```

## Database ER Overview

```
users (1)───<student_profiles (1)      [role = student]
users (1)───<companies (1)             [role = recruiter]
companies (1)───<jobs (many)
jobs (1)───<applications>───(1) users  [role = student]
users (1)───<password_resets (many)    [optional, password-reset flow]
```

Key constraints:

- `applications` has `UNIQUE(job_id, student_id)` — one application row per
  student per job (re-applying after withdrawal updates the existing row).
- All foreign keys are `ON DELETE CASCADE`, so deleting a job/company/user
  cleans up dependent rows.
- Indexes on `jobs.title`, `jobs.location`, `jobs.job_type`, `jobs.status` and
  `applications.status` back the search/filter/dashboard queries.

## Deployment Notes

- Set `APP_ENV` to anything other than `local` in `.env` — that turns off on-screen
  error display and marks the session cookie `Secure` (so serve over HTTPS).
- Point the document root at the project folder so `.htaccess` takes effect; it
  denies direct requests to `.env`, `.sql`, `.md` and the shared include files.
- `uploads/.htaccess` disables PHP execution and directory listing inside the
  uploads folder — keep it in place.
- Never commit `.env`.
