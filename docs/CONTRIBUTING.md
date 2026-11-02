# Working together

## Branches

- `main` is always working code. Don't commit straight to it.
- Start each piece of work on its own branch from an up-to-date `main`:
  ```
  git checkout main && git pull
  git checkout -b feature/savings-ledger
  ```
  Name it `feature/...`, `fix/...` or `chore/...`.
- Push the branch and open a pull request into `main`. The other person reviews it before it is merged.
- Keep pull requests small: one feature or one fix each.

## Before you push

- `cd frontend && npm run type-check` passes.
- New tables or columns are in a **new** numbered SQL file under `backend/database/tenant/` (or
  `platform/`), and `php backend/bin/migrate.php` runs cleanly on your machine.
- No secrets: `backend/.env` stays out of git.

## After you pull

```
php backend/bin/migrate.php
cd frontend && npm install
```

## Money code

- Amounts are `DECIMAL(15,2)` in the database, never `FLOAT`.
- Balances come from the transaction ledger, never stored and edited directly.
- A change that touches money runs in one database transaction (`Database::transaction`), so it
  all saves or none of it does.
- Anything an admin does goes to the audit log (`Audit::log`).

## Style

- PHP: `declare(strict_types=1);`, 4-space indent, one class per file under `backend/src/`.
- Vue: `<script setup lang="ts">`, Tailwind classes, the shared `.btn-*`, `.input`, `.card` styles
  in `src/style.css`.
- Messages people see are plain, friendly sentences ("That email and password don't match"),
  never technical errors.
