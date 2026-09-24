# Implementation verification — 24 September 2026

## Implemented

The selected audit defects have been addressed:

- Vendor registration persists the salon correctly. Admin approval/rejection updates the vendor and salon together; historical pending-account mismatches are repaired without reactivating disabled accounts.
- Empty salons render safely with explicit empty states and a local image placeholder.
- Bookings confirm automatically. Transactional availability checks reject overlaps, past times, out-of-hours intervals, inactive specialists, unavailable services and inactive salon owners.
- Cancelled slots can be reused. Rescheduling moves the existing booking atomically and retains its original snapshots and price.
- Only customers can cancel/reschedule their own confirmed appointments, with at least 30 minutes remaining. The new rescheduled start also requires 30 minutes' notice.
- Disabled accounts lose session access. Missing, empty and malformed CSRF tokens are rejected. Logout requires POST and CSRF.
- Apache blocks Git metadata, database files, tests, private configuration, documentation and backup extensions. Malformed request fields produce controlled errors without stack traces.
- Vendor profile/hours, service, specialist and assignment management are implemented, with ownership checks and future-appointment warnings.
- Vendors can complete ended appointments and record simulated cash collection, but cannot cancel/reschedule or approve bookings.
- Simulated UPI/card success, failure, retries and full cancellation refunds are implemented. They never move real money.
- Booking changes and payment events have histories. Customers and vendors receive in-app notifications with read controls.
- PHP/SQL timezones agree on India Standard Time. Upcoming bookings, member-since display and collection totals have been corrected.

## Verification

- Domain checks cover deadlines at 30:01 / 30:00 / 29:59, stale forms, interval boundaries, ownership, inactive records, snapshots, rollback after storage failure, cash collection and completion.
- Independent PHP processes verify competing bookings, duplicate request keys and payment/cancellation races.
- HTTP checks exercise registration, approval, role/session access, all new pages, vendor setup, immediate booking, expired deadlines, simulated payments, rescheduling, cancellation, refunds, notifications and POST logout.
- Apache private-file checks run against the actual XAMPP server, not only the development router.
- Migration checks cover legacy and fresh installs, repeat execution, snapshot backfill, mismatch repair, deliberate account disablement, backup restoration and refusal of conflicting data.
- Browser verification covers mobile (390×844) and desktop (1280×900), a complete customer booking/payment/reschedule/cancel/refund flow, keyboard rescheduling, vendor profile saving, form validation and management navigation. The mobile toolbar overflow found during verification was corrected.
- Existing local appointments were preserved. Destructive/concurrency tests use isolated databases. No external messages or real transactions are sent.

## Operational notes

The local database migration is applied. A pre-migration backup was created outside the web root; the runner prints its full location. Fresh installs use the updated SQL seed; existing installs use the migration runner. The README explains backup recovery, test commands and deployment credentials.

The demo uses third-party Bootstrap, icons, fonts and images. Browser verification was performed with those resources available. Offline asset bundling, large-scale load testing and a comprehensive accessibility audit are not included.

## Remaining outside this plan

Real payment gateways, email/SMS, reviews, favorites, galleries, password recovery, account profile editing, weekly shifts, specialist leave and overnight hours remain unimplemented. Login throttling and a broader production penetration test are follow-up hardening work. XAMPP root defaults and seeded demo credentials remain development-only; deployment instructions require dedicated credentials and disabled/replaced demo accounts.

Legacy appointments backfilled from existing data cannot recover names or service descriptions that had already changed before this migration. New bookings preserve their own snapshots.
