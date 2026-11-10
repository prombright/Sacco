# Putting SACCO live (cPanel)

This puts SACCO on the St Mark's cPanel server (account `smacon`) at its own address, e.g.
**https://sacco.stmark.sc.ug**. Allow about an hour the first time.

## 1. In cPanel

1. **Subdomain** (Domains → Create a new domain): `sacco.stmark.sc.ug`, document root
   `/home/smacon/sacco` (the repository folder itself; its `.htaccess` sends `/api` to PHP and
   everything else to the app, and keeps `backend/` private).
2. **SSL**: SSL/TLS Status → run AutoSSL for the new subdomain. The site forces HTTPS.
3. **MySQL database** (MySQL Databases): everything lives in **one** database.
   - create `smacon_sacco`
   - create a user, e.g. `smacon_saccouser`, with a long random password
   - add the user to the database with **All privileges**
4. **PHP version** (MultiPHP Manager): 8.2 or newer for the subdomain.

## 2. The code (over SSH, from the laptop)

```
ssh -i C:\Users\HP\.ssh\espace_deploy smacon@server1.stmark.sc.ug
cd ~
git clone https://github.com/prombright/Sacco.git sacco
cd sacco/backend
cp .env.example .env
nano .env
```

Set in `.env`:

```
APP_ENV=production
APP_DEBUG=false
DB_HOST=localhost
DB_USER=smacon_saccouser
DB_PASS=<the password from step 1>
PLATFORM_DB_NAME=smacon_sacco
DEFAULT_SACCO_CODE=smacon
MYSQLDUMP_PATH=mysqldump
APP_URL=https://sacco.stmark.sc.ug
```

**Email** (copies of members' messages, and "forgot password" links): in cPanel → Email Accounts,
create a mailbox such as `no-reply@stmark.sc.ug`; "Connect Devices" shows its outgoing server.
Then add to `.env`:

```
MAIL_DRIVER=smtp
MAIL_HOST=<outgoing server, e.g. mail.stmark.sc.ug>
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=no-reply@stmark.sc.ug
MAIL_PASSWORD=<the mailbox password>
MAIL_FROM_ADDRESS=no-reply@stmark.sc.ug
MAIL_FROM_NAME=SMACON SACCO
```

(`MAIL_DRIVER=mail` also works on most cPanel servers with no other settings, but those emails
land in spam more often. Without email, messages still reach members inside the app.)

Then create SMACON with its first super admin (prints a temporary password, once). `--db` is the
same database as `PLATFORM_DB_NAME`, so the list of SACCOs and SMACON's data share it:

```
php bin/create-sacco.php --code=smacon --name="SMACON SACCO" --short=SMACON \
    --admin-name="<treasurer's name>" --admin-email=<their email> --db=smacon_sacco
php bin/migrate.php
```

## 3. The app's screens

The server has no Node, so build on the laptop and upload the result:

```
cd C:\xampp\htdocs\sacco\frontend
npm run build
scp -i C:\Users\HP\.ssh\espace_deploy -r dist smacon@server1.stmark.sc.ug:~/sacco/frontend/
```

(`npm run build` is for the subdomain's root. `build:local` is only for http://localhost/sacco.)

## 4. Nightly backups

cPanel → Cron Jobs → add, once a day at 02:00:

```
0 2 * * * /usr/local/bin/php /home/smacon/sacco/backend/bin/backup.php >/dev/null 2>&1
```

Backups go to `~/sacco/backend/storage/backups/`, 30 days are kept. Download one now and then
to keep a copy away from the server. To restore: `gunzip -c file.sql.gz | mysql -u USER -p DBNAME`.

Loan reminders and credit standings run by themselves once a day (on the first look at the bell).
To send them at a set time instead, add a second cron job, e.g. 07:00:

```
0 7 * * * /usr/local/bin/php /home/smacon/sacco/backend/bin/daily.php >/dev/null 2>&1
```

Members can upload photos up to 5 MB: in cPanel -> MultiPHP INI Editor, set `upload_max_filesize`
and `post_max_size` to at least 6M. After the first deploy with migration 017, run
`php backend/bin/rebuild-loans.php` once so past repayments count towards credit standings.

## 5. Updating later

```
cd ~/sacco && git pull && php backend/bin/migrate.php
```

and if the screens changed, build and `scp` the `dist` folder again (step 3).

## 6. The developer portal

`/devportal` is for the people who run this installation (not SACCO staff). It shows every SACCO's
figures, members, office accounts and full audit log; it can change a SACCO's details and look,
pause it, add super admins and admins, reset passwords, apply database updates, and set up new
SACCOs. Every change is logged in the platform's `platform_audit` table and in the SACCO's own
audit log, and the SACCO's office accounts get a message about it.

Developers are added from the server only:

```
php backend/bin/create-developer.php --name="Jane Doe" --email=jane@example.com
```

It prints a temporary password, changed at first sign-in. Run it again with the same email to reset
a developer's password.

## 7. Security settings on the live server

- In `backend/.env`: `APP_ENV=production` and `APP_DEBUG=false`. Production marks the sign-in cookie
  secure and turns on strict HTTPS for the API.
- Apache needs `mod_headers` (cPanel has it): the root `.htaccess` sends the security headers for
  the app's pages, and HTTPS is forced everywhere except localhost.
- To limit the developer portal to your own internet addresses, set
  `DEV_ALLOWED_IPS=1.2.3.4,5.6.7.8` in `backend/.env`. Everyone else gets "not found".
- Run `php backend/bin/migrate.php` after every update, for the platform database and each SACCO.
- Sign-ins: an account locks after 5 wrong passwords (15 minutes); one internet address is blocked
  after 30 failures in 10 minutes. A password change or reset ends that account's other sessions.

## Going live with St Mark's: checklist

Do these in order, signed in as the super admin.

1. **Change the temporary password** (you'll be asked on first sign-in).
2. **SACCO rules** (Office → SACCO rules):
   - loan products: check "Normal loan" (rate, months, limit × savings, fee, guarantors); add
     others the committee uses (emergency, school fees...)
   - months of saving before a loan (6), months of membership to guarantee (6)
   - late-payment penalty % (0 if the committee hasn't agreed one), share price
3. **Second admin**: add the treasurer/secretary as an Admin (Members → Add member, role Admin).
   Two people are needed anyway: nobody approves their own loan or posts to their own account.
4. **Import members** (Members → Import from Excel): every member with their **member since**
   date and their **savings and shares from the old records** as opening balances. Check the
   totals on the preview against the old books before adding.
5. **Loans already running** in the old records: Office → Loans → **Record a loan from old
   records** for each one: member, type, amount, months, the date it was paid out and the total
   repaid so far. The schedule runs from the real payout date, so late instalments show as late.
6. **Next of kin**: members can add their own (My profile); admins can for anyone.
7. **Hand out sign-in details**: the import downloads an Excel file with each member's temporary
   password. Give each person only their own line.
8. **Post the month's payroll** (Office → Payroll uploads) when it comes in.
9. **Notice** to everyone (Office → Notices): "SACCO is now online - sign in at ..."

## Known limits (tell the committee)

- **Old loans** are entered one at a time (no Excel import for loans yet).
- **Interest on early or extra repayments**: an extra payment goes to the next instalments; only
  the "Pay off early" action recalculates interest.
- **SMS / email alerts** aren't sent yet; members see everything when they sign in.
- **Staff list from the HR API**: waiting for its details; members are added by hand or Excel.
