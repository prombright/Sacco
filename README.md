# SACCO

A savings and credit system for staff SACCOs: members' savings and shares, loans with guarantors,
withdrawals, dividends, and monthly payroll uploads. One installation serves many SACCOs; each
SACCO has its own database. The first is SMACON SACCO (St. Mark's School staff).

## Stack

- **frontend/**: Vue 3 + TypeScript + Tailwind CSS (Vite)
- **backend/**: plain PHP 8.2 API (no framework, no Composer packages yet) + MySQL/MariaDB
- Runs on XAMPP locally and on cPanel (Apache) in production

## Project layout

```
backend/
  public/index.php      API entry point; every /api/... request lands here
  routes.php            all API routes, with who may call each
  src/                  PHP classes (namespace Sacco\ -> src/)
    Auth/               sign-in, roles, password rules
    Controllers/        one class per area (AuthController, UserController...)
    Http/               Request, Response, Router
    Support/            Audit log, Migrator
    Database.php        connections (platform DB + current SACCO's DB)
    Tenant.php          which SACCO a request belongs to
  database/
    platform/           SQL for the platform DB (list of SACCOs)
    tenant/             SQL for each SACCO's own DB
  bin/                  command-line scripts (migrate, create-sacco)
frontend/
  src/pages/            one Vue file per screen
  src/components/       shared pieces (Modal, PasswordInput...)
  src/stores/           Pinia stores (auth)
  src/api.ts            API client
docs/                   how we work
```

## Local setup (XAMPP)

1. Clone into `C:\xampp\htdocs\sacco` so Apache serves it at http://localhost/sacco.
2. Backend settings: copy `backend/.env.example` to `backend/.env` (the defaults suit XAMPP).
3. Create the databases and a SACCO with its first super admin:
   ```
   php backend/bin/create-sacco.php --code=smacon --name="SMACON SACCO" --short=SMACON --admin-name="Your Name" --admin-email=you@example.com
   ```
   It prints a temporary password; sign in with it and you'll be asked to choose your own.
   (`php` is `C:\xampp\php\php.exe` if it isn't on your PATH.)
4. Frontend:
   ```
   cd frontend
   npm install
   npm run dev
   ```
   Open http://localhost:5174. Vite forwards `/api` to the PHP API in XAMPP.

**After every pull** run `php backend/bin/migrate.php`, which applies any new SQL files to the
platform database and to every SACCO's database.

To try the built app through Apache instead (http://localhost/sacco), run `npm run build:local`.

## Users and sign-in

- Roles: **Super Admin** (everything, including managing admins), **Admin** (runs the SACCO,
  manages members), **Member** (a staff member).
- People sign in with their **email or employee number**.
- New users and password resets get a **unique random temporary password**, shown once to the
  admin. The user must replace it before they can do anything else.
- Passwords: 8+ characters, letters and numbers, not their email or employee number, and not one
  of their last 5. Five wrong attempts lock the account for 15 minutes.
- Every sign-in attempt goes to `login_log`; every change by an admin goes to `audit_log`.

## Money rules (agreed so far)

- Loan interest: **1% per month on a reducing balance**, equal monthly instalments.
- Loan limit: **2 × savings**, minus what they already owe.
- A borrower must have **saved for 6 months**; a guarantor must have been a **member for 6 months**.
  Membership counts from the member's "Member since" date, or their first savings entry if that's blank.
- Guarantors must have the amount free in their savings (not pledged elsewhere or being withdrawn),
  and may agree to a smaller amount than the borrower asked for.
- These live in each SACCO's `settings` table (Office → Rules) so they can be changed without code changes.

## Payroll file format

The payroll upload reads the first sheet of an .xlsx, .xls or .csv file. It finds the header row
by itself (title rows above it are fine), matches columns by name, and skips blank and TOTAL lines.

Our own template (Office → Payroll → Download template) has: Account No, Name, Savings, Shares,
Loan Repayment, Loan No. When the SACCO's real payroll sheet arrives, add its column headings to
`COLUMNS` in `frontend/src/payroll.ts`. Nothing else needs to change.

Every upload is checked first and nothing is saved until it is posted. Posting saves every row
in one database transaction (all of it or none). A posted upload can be reversed as a whole.

## Database changes

Never edit a SQL file that has been committed. Add a new numbered file
(`backend/database/tenant/002_savings_ledger.sql`), then run `migrate.php`.

## Deploying (cPanel)

1. Create the databases in cPanel (MySQL user with access to both), then set `backend/.env` on
   the server with `APP_ENV=production` and `APP_DEBUG=false`.
2. Run `npm run build` (or `build:local` for a `/sacco/` subfolder) and upload `frontend/dist`.
3. Run `php backend/bin/migrate.php` on the server.

The root `.htaccess` sends `/api/...` to the PHP API and everything else to the built app;
`backend/` itself is never served.

See [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) for how we work together.
