# The Barber Company

A core PHP 8 + MySQL multi-vendor salon booking platform for XAMPP.

## Setup

1. Start Apache and MySQL in XAMPP.
2. Open phpMyAdmin and import `database/database.sql`.
3. Visit `http://localhost/Barber/`.
4. If your folder is not named `Barber`, update `APP_URL` in `includes/functions.php`.

Demo accounts use the password `password`:

- Admin: `admin@thebarbercompany.test`
- Vendor: `vendor@thebarbercompany.test`
- Customer: `customer@thebarbercompany.test`

The database is designed around role-based users, approved salons, services, staff-to-service assignments, collision-safe appointments, simulated payments, reviews, favorites, gallery items, and notifications. Vendor applications are pending until an admin approves them.
