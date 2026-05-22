# Start unified analytics API (v2) on port 8002
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$py = Join-Path $PSScriptRoot ".venv\Scripts\python.exe"
if (-not (Test-Path $py)) {
    Write-Error "Missing .venv. Run: python -m venv .venv && .\.venv\Scripts\pip install -r requirements.txt"
}

# Stop previous listener on 8002 (old overview_app)
$conn = Get-NetTCPConnection -LocalPort 8002 -ErrorAction SilentlyContinue | Select-Object -First 1
if ($conn) {
    Stop-Process -Id $conn.OwningProcess -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
}

Write-Host "Starting Reserva FTIC Analytics v2 on http://127.0.0.1:8002"
& $py main.py
