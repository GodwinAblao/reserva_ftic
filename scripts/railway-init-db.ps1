# Initialize Railway PostgreSQL (empty database).
# Usage:
#   copy .env.railway.local.example .env.railway.local   # paste Railway Connection URL
#   .\scripts\railway-init-db.ps1
#   .\scripts\railway-init-db.ps1 -Seed

param([switch]$Seed)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $root

if (-not $env:DATABASE_URL) {
    if (Test-Path ".env.railway.local") {
        Get-Content ".env.railway.local" | ForEach-Object {
            if ($_ -match '^\s*DATABASE_URL=(.+)$') {
                $env:DATABASE_URL = $matches[1].Trim().Trim('"')
            }
        }
    }
}

if (-not $env:DATABASE_URL) {
    Write-Host "ERROR: Set DATABASE_URL or create .env.railway.local with your Railway connection string." -ForegroundColor Red
    Write-Host "Railway dashboard → reserva-ftic-db → Connect → copy Connection URL"
    exit 1
}

Write-Host "Target: Railway PostgreSQL" -ForegroundColor Cyan
Write-Host "Running schema:create (PostgreSQL-safe)..." -ForegroundColor Yellow

php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:schema:create
php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
php bin/console doctrine:migrations:version --add --all --no-interaction

if ($Seed) {
    php bin/console app:seed-facilities
}

Write-Host "Done. Verify:" -ForegroundColor Green
php bin/console dbal:run-sql "SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
