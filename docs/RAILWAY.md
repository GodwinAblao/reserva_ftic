# Railway setup (Symfony + Supabase PostgreSQL)

Deploy guide: see [RAILWAY-DEPLOY.md](./RAILWAY-DEPLOY.md).

## Database

The production database is Supabase PostgreSQL.

Use the exact Supabase PostgreSQL connection string for `DATABASE_URL`.

```text
postgresql://USER:PASSWORD@HOST:PORT/postgres?serverVersion=16&charset=utf8
```

For Railway, prefer the Supabase transaction pooler connection string.

```text
postgresql://postgres.PROJECT_REF:PASSWORD@POOLER_HOST:6543/postgres?serverVersion=16&charset=utf8
```

## Required Web Service Variables

```env
APP_ENV=prod
APP_SECRET=your-random-secret
COMPOSER_ALLOW_SUPERUSER=1
DATABASE_URL=your-supabase-postgres-url
MAILER_DSN=null://null
NIXPACKS_PHP_ROOT_DIR=/app/public
NIXPACKS_PHP_FALLBACK_PATH=/index.php
RAILPACK_PHP_ROOT_DIR=/app/public
```

Use `MAILER_DSN=null://null` while testing. Replace it with a real SMTP provider after the site loads correctly.

## Local vs Railway

| File or source | Database |
| --- | --- |
| `.env` | Safe defaults only |
| `.env.local` | Supabase PostgreSQL for local debugging, ignored by Git |
| Railway service variables | Supabase PostgreSQL for production |

Do not commit production passwords or secrets.
