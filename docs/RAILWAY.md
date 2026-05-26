# Railway setup (Symfony + PostgreSQL)

**Deploy guide:** see [RAILWAY-DEPLOY.md](./RAILWAY-DEPLOY.md)

## 1. Database (`reserva-ftic-db`)

Already created and initialized (schema + facilities). Connection URL format:

```text
postgresql://postgres:PASSWORD@zephyr.proxy.rlwy.net:PORT/railway?sslmode=require
```

## 2. Link database to the web service

1. Railway project → **Symfony/web service** (not the DB).
2. **Variables** → **New Variable** → **Add reference** → select `reserva-ftic-db` → `DATABASE_URL`.
3. Or paste the full Connection URL as `DATABASE_URL`.
4. Add: `APP_ENV=prod`, `APP_SECRET=` (random string), `MAILER_DSN=` (your SMTP).
5. **Deploy** the web service (Dockerfile from repo root).

Use **Private Network** between services in the same project when possible.

## 3. Initialize tables (first time only)

From your PC (once), with the Railway password:

```powershell
cd C:\Users\user\reserva_ftic
copy .env.railway.local.example .env.railway.local
# Edit .env.railway.local — paste Connection URL from Railway (show password)

.\scripts\railway-init-db.ps1
```

Or set env and run:

```powershell
$env:DATABASE_URL = "postgresql://postgres:PASSWORD@HOST:PORT/railway?serverVersion=16&charset=utf8&sslmode=require"
.\scripts\railway-init-db.ps1
```

## 4. View data

- **pgAdmin**: register server with Railway host/port/user/password, database `railway`.
- **Railway**: database service → **Data** tab (if available on your plan).

## 5. Local vs Railway

| File | Database |
|------|----------|
| `.env` | XAMPP MySQL (default dev) |
| `.env.local` | Local PostgreSQL 18 |
| `.env.railway.local` | Railway (init script only; gitignored) |
| Railway service vars | Production `DATABASE_URL` |

Do not commit passwords. Rotate if exposed.
