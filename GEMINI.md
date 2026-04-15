# GEMINI.md - AMV Hotel Management System

## Project Overview
AMV Hotel Management System is a comprehensive web-based application designed for managing hotel operations, including room bookings, food ordering, events, and news updates. It features a secure admin dashboard with analytics and a user-facing platform for guests to interact with hotel services.

### Main Technologies
- **Backend:** PHP 8.x
- **Database:** MySQL/MariaDB (using `mysqli` extension)
- **Frontend:** HTML5, CSS3 (Montserrat font), JavaScript (ES6+)
- **Analytics:** Chart.js for dashboard visualizations
- **Email:** PHPMailer for automated notifications and OTP delivery
- **Authentication:** Session-based with OTP (One-Time Password) for Admin security; Social login support (Google/Facebook) for users.

### Architecture
The project is divided into three main modules:
1.  **ADMIN/**: Contains all administrative logic, including dashboard, guest management, booking approvals, food order tracking, and system settings.
2.  **USER/**: The guest-facing website providing features like room selection, booking process, menu browsing, and event galleries.
3.  **API/**: A set of RESTful API endpoints used for data synchronization and potentially supporting a mobile application or decoupled frontend.

## Directory Structure
- `ADMIN/PHP/`: Core administrative scripts and business logic.
- `USER/PHP/`: User-facing scripts and page templates.
- `API/`: Public-facing API endpoints and configurations.
- `DATABASE/`: Contains `amv_db.sql` for schema initialization.
- `IMG/` & `image-storage/`: Static assets and dynamic user/admin uploads.
- `room_includes/`: Shared components and helper classes like `ImageManager.php`.

## Setup and Running

### Prerequisites
- XAMPP / WAMP / MAMP (PHP 8.0+, MySQL 5.7+)
- SMTP Server access (for OTP and email features)

### Installation
1.  **Database:** Import `DATABASE/amv_db.sql` into your MySQL server.
2.  **Configuration:**
    - Update `ADMIN/PHP/db_connect.php` and `USER/DB-CONNECTIONS/db_connect.php` with your local database credentials.
    - Adjust `API/config.php` with your server's IP address if testing via a network or mobile device.
    - Ensure `ADMIN/PHP/config.php` has the correct absolute paths for file uploads on Windows.
3.  **Permissions:** Ensure the `uploads/` and `image-storage/` directories are writable by the web server.

### Key Commands
- **Local Server:** Access via `http://localhost/AMV_Project_exp/USER/PHP/index.php` (User side) or `http://localhost/AMV_Project_exp/ADMIN/PHP/index.php` (Admin side).

## Development Conventions

### Security Practices
- **Prepared Statements:** Always use prepared statements (`$stmt->prepare()`) for database queries to prevent SQL injection.
- **Input Sanitization:** Sanitize all user inputs using `trim()`, `strip_tags()`, and `filter_var()`.
- **Session Security:** Maintain secure session headers (X-Frame-Options, CSP, HttpOnly cookies) as seen in `index.php`.
- **OTP Auth:** Admin login requires a 6-digit OTP sent via email, valid for 10 minutes.
- **Daily Message Limit:** Guests are limited to sending 4 messages per day per email address to prevent spam. This is enforced across all USER modules and API endpoints.

### Coding Style
- **File Naming:** Logic files are typically in `PHP/` subdirectories. Asset directories use uppercase (e.g., `IMG`, `STYLE`, `SCRIPT`).
- **PHP Tags:** Avoid closing PHP tags (`?>`) in logic-only files to prevent accidental whitespace output.
- **Responsive Design:** Utilize mobile-first or flexible grid layouts (e.g., `stats-grid` in the dashboard).

### TODOs / Improvements
- [ ] Centralize database connection logic to a single shared directory to avoid duplication.
- [ ] Implement environment variables (`.env`) for sensitive credentials instead of hardcoding in `config.php`.
- [ ] Standardize API responses to consistent JSON formats.
