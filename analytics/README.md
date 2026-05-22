# Reserva FTIC Analytics

Unified analytics API: **live MySQL** when reservations exist, **demo CSV** otherwise.

## Start (required for full ARIMA charts)

```powershell
cd analytics
.\.venv\Scripts\pip install numpy pandas statsmodels pymysql --only-binary :all:
.\.venv\Scripts\python main.py
```

Runs at **http://127.0.0.1:8002**

## Endpoints

| Endpoint | Description |
|----------|-------------|
| `GET /api/analytics/overview` | 30-day dashboard chart |
| `GET /api/analytics/planning` | ARIMA weekly/monthly + per-facility |
| `GET /api/analytics/organizing` | Load, utilization, heatmap |
| `GET /api/analytics/staffing` | Staff ratio trends |
| `GET /api/analytics/leading` | Success by purpose |
| `GET /api/analytics/controlling` | Status, compliance |
| `GET /api/analytics/export/weekly-forecast` | Download ARIMA CSV |
| `GET /api/analytics/export/this-week` | Download this-week CSV |

Optional query: `?facility_id=3`

## Data mode

- **Live**: any reservation in DB (excluding `AwaitingFacilitySelection`)
- **Demo**: `data/dummy_reservations.csv` when DB is empty

## Symfony UI

- **Managerial Function** → `/analytics` (Chart.js dashboards)
- **Reports** → CSV downloads + ARIMA exports
