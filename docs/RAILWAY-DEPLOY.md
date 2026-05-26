# Deploy Reserva FTIC on Railway (with Railway PostgreSQL)

Your database **`reserva-ftic-db`** is already initialized (tables + facilities).

## Step 1 — Create the web service (if you do not have one yet)

1. [railway.app](https://railway.app) → your project.
2. **+ New** → **GitHub Repo** → select `reserva_ftic` (or **Empty Service** → connect repo later).
3. Railway detects **`railway.toml`** + **`Dockerfile`** → builds with Docker.

## Step 2 — Link the database (required)

1. Click your **web service** (Symfony app), not the database.
2. **Variables** tab.
3. **+ New Variable** → **Add variable reference** (or "Reference"):
   - Service: `reserva-ftic-db`
   - Variable: `DATABASE_URL`
4. Add these manually:

| Variable | Value |
|----------|--------|
| `APP_ENV` | `prod` |
| `APP_SECRET` | Long random string (e.g. 32+ chars) |
| `MAILER_DSN` | Your SMTP URL (Brevo/SendGrid/Gmail app password) |
| `DEFAULT_URI` | Your public URL, e.g. `https://your-app.up.railway.app` |

**Do not** paste `DATABASE_URL` from `.env` (MySQL). The reference to `reserva-ftic-db` is correct.

Symfony uses the **environment** `DATABASE_URL` on Railway; it overrides the MySQL line in committed `.env`.

## Step 3 — Deploy

1. **Deploy** (or push to the connected GitHub branch).
2. First deploy runs `docker-entrypoint.sh` → creates schema if DB were empty (yours is already done).
3. Open the generated **Public URL** under **Settings → Networking → Generate Domain**.

## Step 4 — Create a user on production

Railway DB has facilities but no users. On the live site:

- **Register** a new account, or
- Run once in **Railway Shell** on the web service:
  ```bash
  php bin/console doctrine:query:sql "SELECT email FROM \"user\" LIMIT 5"
  ```

## Local development vs production

| Environment | Config file | Database |
|-------------|-------------|----------|
| Normal dev (XAMPP) | `.env` only (rename `.env.local` away) | MySQL localhost |
| Local Postgres test | `.env.local` | `127.0.0.1` / `reserva_ftic` |
| **Production (Railway)** | Service **Variables** | `reserva-ftic-db` |

Keep **`.env.local`** on local Postgres for dev. Production uses Railway variables only.

## Optional — test production DB from your PC

Only for debugging (uses public network / egress):

```powershell
copy .env.railway.local.example .env.railway.local
# paste Connection URL, then:
.\scripts\railway-init-db.ps1
php bin/console dbal:run-sql "SELECT COUNT(*) FROM facility"
```

## Troubleshooting

| Problem | Fix |
|---------|-----|
| 500 / database error | Web service must reference `reserva-ftic-db` `DATABASE_URL` |
| `could not find driver` | Dockerfile includes `pdo_pgsql` — redeploy |
| Login works locally, not online | Register user on Railway DB (separate from XAMPP) |
| Migrations fail | Expected on Postgres; schema already created via init script |

## Security

Rotate the database password in Railway if it was shared. Update the variable reference after rotation.
