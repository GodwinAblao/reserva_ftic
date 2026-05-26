# Deploy Reserva FTIC on Railway

This project deploys on Railway with Nixpacks.

## 1. Create Or Open The Web Service

1. Open the Railway project.
2. Connect the GitHub repository.
3. Railway reads `railway.toml`.
4. `railway.toml` uses:

```toml
[build]
builder = "NIXPACKS"
```

## 2. Configure Variables

Set these on the Railway web service:

| Variable | Value |
| --- | --- |
| `APP_ENV` | `prod` |
| `APP_SECRET` | Long random string |
| `COMPOSER_ALLOW_SUPERUSER` | `1` |
| `DATABASE_URL` | Reference the `reserva-ftic-db` PostgreSQL service |
| `MAILER_DSN` | `null://null` while testing |
| `NIXPACKS_PHP_ROOT_DIR` | `/app/public` |
| `NIXPACKS_PHP_FALLBACK_PATH` | `/index.php` |
| `RAILPACK_PHP_ROOT_DIR` | `/app/public` |

Do not use the local MySQL URL from `.env` for production.

## 3. Deploy

Push to the connected branch or trigger a Railway redeploy.

The start command in `railway.toml` runs Doctrine migrations, then starts PHP on Railway's `$PORT`:

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=prod && php -S 0.0.0.0:$PORT -t public public/index.php
```

## 4. Troubleshooting

| Problem | Fix |
| --- | --- |
| 500 on `/` but `/health` works | Check Railway logs for the Symfony exception |
| Database connection error | Verify the web service has `DATABASE_URL` from `reserva-ftic-db` |
| `could not find driver` | Ensure `ext-pdo_pgsql` is in `composer.json`, then redeploy |
| Login works locally, not online | Register a user in the Railway database |
| SMTP errors | Keep `MAILER_DSN=null://null` until the app works |

## 5. Email

After the app loads correctly, replace `MAILER_DSN=null://null` with a third-party SMTP provider such as Resend, SendGrid, Mailgun, Brevo, or Postmark.
