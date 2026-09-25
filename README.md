# The Barber Company

Core PHP 8.2 + MariaDB salon booking application for XAMPP.

## Local setup

1. Start Apache and MySQL in XAMPP.
2. For a **new database only**, import `database/database.sql` in phpMyAdmin. The current schema and demo data are included.
3. For an **existing installation**, do not reimport the seed file. Run:
   ```powershell
   & C:/xampp/php/php.exe scripts/migrate.php
   ```
4. Visit [the local website](http://localhost/Barber/).
5. If the project directory changes, update `APP_URL` in `includes/functions.php`.

The migration has been applied to this local installation. It preserves existing appointments, backfills their snapshots, repairs pending vendor accounts whose salons were already reviewed, and replaces the old start-time uniqueness constraint.

Demo accounts use password `password`: `admin@thebarbercompany.test`, `vendor@thebarbercompany.test`, `customer@thebarbercompany.test`. These are development accounts; disable them or replace their credentials before public hosting.

## Booking and appointment rules

- Bookings confirm immediately and appear on the vendor Appointments page. No vendor confirmation is required.
- Customers may select several services in one appointment. When one active specialist offers all of them, the customer chooses that person. Otherwise the system assigns an active qualified specialist to each service. Services run consecutively; one booking and one simulated payment cover the combined price and duration. Availability, rescheduling and cancellation account for every specialist's reserved segment.
- All dates and times use India Standard Time, `Asia/Kolkata`.
- Only the owning customer can cancel/reschedule a confirmed booking. At least **30 minutes** must remain before its current start; exactly 30 minutes is allowed. The server rechecks this after locking the appointment.
- A rescheduled appointment must also start at least 30 minutes in the future. Its service, specialist, duration, price, booking code and payment link remain unchanged.
- A cancelled or successfully rescheduled slot becomes available immediately. A new booking may fill it at any future time that fits salon hours and does not overlap another reservation.
- Vendors may mark their own confirmed appointments completed after the scheduled end.
- Booking retries are idempotent. Scheduling changes, records, history and in-app notifications are transactional.

## Vendor tools

After admin approval activates the vendor account, use **Manage salon** in the vendor navigation.

Vendors land on a dashboard showing total appointments, completed appointments and existing visible customer reviews. The separate Appointments page lists upcoming visits above previous appointments, with filters and pagination. Cancelled bookings are red, paid prepaid bookings green and cash bookings yellow; text labels also identify each state.

Customers keep the salon discovery interface. The profile avatar beside Sign out opens Edit profile and Notifications; its badge shows unread notifications and refreshes periodically. All roles can edit their own name, email and phone number.

Admins land on the approval dashboard. **Manage salons** provides a searchable directory with salon, owner, service and staff information. Each role has its own navigation and workspace styling.

Manage profile/contact information, image URL, daily opening hours, services/categories/prices/durations, specialists and service assignments. Deactivate records instead of deleting appointment history. Availability edits warn about future appointments and do not silently cancel them. Existing appointments retain booked snapshots.

Hours apply every day, within one calendar day. Weekly shifts, leave and overnight opening hours are not implemented. Vendors cannot cancel or reschedule customers' appointments.

## Payment simulation

**No real payments, charges or refunds occur. Do not enter real banking/card details.**

UPI/card bookings confirm before the customer chooses simulated success or failure on their booking details page. Failures can be retried without releasing the reservation. Cash stays pending until the vendor records simulated collection at or after the appointment start.

Eligible cancellation of a paid booking triggers a full simulated refund. Payment status and appointment completion are separate. Simulated references and events are recorded; vendor/admin collection totals exclude refunds.

## Migration and recovery

The CLI runner enables maintenance, drains in-flight application requests using a filesystem lock, checks overlapping active appointments, invalid intervals and payment inconsistencies, and creates a timestamped SQL backup in the operating system temporary directory **outside the web root** before changing schema.

- Copy the backup to durable private storage before production rollout.
- The runner records applied versions and is safe to repeat.
- On failure, maintenance remains enabled. Investigate the reported anomaly instead of deleting bookings to force migration through.
- MariaDB DDL is not fully transactional. To roll back, restore the printed backup into the same database using phpMyAdmin or the MySQL CLI and restore the matching pre-deployment application revision before removing `config/maintenance.lock`.
- A preflight failure occurs before any schema changes. After correcting the data, remove that lock and rerun.
- Do not run manual database writers or other CLI jobs during a production migration. They are outside the application request lock.

## Deployment security

Apache must allow this project's `.htaccess` rules and enable `mod_rewrite`. Private directories and common backup/configuration file extensions return 403; index browsing is disabled. Verify this after deployment.

Configure `BARBER_DB_HOST`, `BARBER_DB_NAME`, `BARBER_DB_USER` and `BARBER_DB_PASS` in the server environment. The defaults are for local XAMPP only. Use a dedicated runtime database account with SELECT/INSERT/UPDATE/DELETE privileges on this database; run migrations separately with DDL privileges. Do not store passwords in tracked files.

Use HTTPS for hosted installations. Session cookies are HttpOnly and SameSite=Lax, with Secure enabled on HTTPS. Public PHP error display is disabled; diagnostics go to the server error log outside the site. Keep backup files and logs out of the document root.

## Tests

Tests mutate **only dedicated test databases**. The HTTP suite also makes read-only requests to the live Apache site to verify private-file restrictions.

First-time test database setup:
```powershell
$testSchema = (Get-Content database/database.sql -Raw).Replace('barber_company','barber_company_test')
$testSchema | & C:/xampp/mysql/bin/mysql.exe -u root
```

Run:
```powershell
& C:/xampp/php/php.exe tests/domain.php
powershell -File tests/start-server.ps1
& C:/xampp/php/php.exe tests/http-regression.php
& C:/xampp/php/php.exe tests/role-ui.php
& C:/xampp/php/php.exe tests/migration.php
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & C:/xampp/php/php.exe -l $_.FullName }
powershell -File tests/stop-server.ps1
```

The domain suite resets fixtures in `barber_company_test`; run it before each HTTP suite run. It includes separate-process concurrency tests. Migration tests create randomly named scratch databases, verify fresh/legacy installs and backup restoration, and remove those scratch databases.

The isolated browser server is [localhost port 8091](http://127.0.0.1:8091/Barber/). Test accounts `customer@example.test`, `vendor@example.test` and `admin@example.test` use `TestPass123!`. They exist only in the isolated test database. Fixtures remain there for optional inspection after the tests; the server is stopped after verification.

See `AUDIT.md` for implementation verification and remaining out-of-scope features.
