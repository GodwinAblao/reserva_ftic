# Railway setup (Symfony + PostgreSQL)

Deploy guide: see [RAILWAY-DEPLOY.md](./RAILWAY-DEPLOY.md).

## Database

The Railway PostgreSQL service is `reserva-ftic-db`.

Use a Railway variable reference for `DATABASE_URL` when possible:

```text
${{reserva-ftic-db.DATABASE_URL}}
```

If pasting a URL manually, use this shape:

```text
postgresql://USER:PASSWORD@HOST:5432/railway?serverVersion=16&charset=utf8
```

## Required Web Service Variables

```env
APP_ENV=prod
APP_SECRET=your-random-secret
COMPOSER_ALLOW_SUPERUSER=1
DATABASE_URL=${{reserva-ftic-db.DATABASE_URL}}
MAILER_DSN=null://null
NIXPACKS_PHP_ROOT_DIR=/app/public
NIXPACKS_PHP_FALLBACK_PATH=/index.php
RAILPACK_PHP_ROOT_DIR=/app/public
```

Use `MAILER_DSN=null://null` while testing. Replace it with a real SMTP provider after the site loads correctly.

## Local vs Railway

| File or source | Database |
| --- | --- |
| `.env` | Local development defaults |
| `.env.local` | Local overrides, ignored by Git |
| `.env.railway.local` | Local Railway debugging only, ignored by Git |
| Railway service variables | Production config |

Do not commit production passwords or secrets.
