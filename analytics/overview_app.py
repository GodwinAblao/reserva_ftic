from datetime import datetime, timedelta
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse
import csv
import os

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware


app = FastAPI(title="Reservation Analytics Overview")

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://127.0.0.1:8000",
        "http://localhost:8000",
    ],
    allow_methods=["GET"],
    allow_headers=["*"],
)


ROOT_DIR = Path(__file__).resolve().parents[1]


def date_keys(days: int) -> list[str]:
    today = datetime.now().date()
    start = today - timedelta(days=days - 1)
    return [(start + timedelta(days=i)).isoformat() for i in range(days)]


def read_database_url() -> str | None:
    env_url = os.getenv("DATABASE_URL")
    if env_url:
        return env_url.strip().strip('"')

    env_file = ROOT_DIR / ".env"
    if not env_file.exists():
        return None

    for line in env_file.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or not line.startswith("DATABASE_URL="):
            continue

        return line.split("=", 1)[1].strip().strip('"')

    return None


def parse_mysql_url(database_url: str) -> dict[str, object] | None:
    parsed = urlparse(database_url)
    if parsed.scheme not in {"mysql", "mariadb"}:
        return None

    query = parse_qs(parsed.query)
    return {
        "host": parsed.hostname or "127.0.0.1",
        "port": parsed.port or 3306,
        "user": unquote(parsed.username or "root"),
        "password": unquote(parsed.password or ""),
        "database": (parsed.path or "/").lstrip("/"),
        "charset": query.get("charset", ["utf8mb4"])[0],
    }


def empty_counts(days: int) -> dict[str, int]:
    return {date: 0 for date in date_keys(days)}


def query_counts_by_date(connection, sql: str, start_date: str, end_date: str) -> dict[str, int]:
    with connection.cursor() as cursor:
        cursor.execute(sql, (start_date, end_date))
        rows = cursor.fetchall()

    return {str(row["dt"]): int(row["cnt"]) for row in rows}


def merge_counts(*series: dict[str, int]) -> dict[str, int]:
    merged: dict[str, int] = {}
    for counts in series:
        for date, count in counts.items():
            merged[date] = merged.get(date, 0) + int(count)

    return merged


def load_counts_from_database(days: int = 30) -> tuple[dict[str, int], dict[str, int]] | None:
    try:
        import pymysql
    except ModuleNotFoundError:
        return None

    database_url = read_database_url()
    if not database_url:
        return None

    config = parse_mysql_url(database_url)
    if not config or not config["database"]:
        return None

    config["cursorclass"] = pymysql.cursors.DictCursor

    dates = date_keys(days)
    start_date = dates[0]
    end_date = dates[-1]

    connection = pymysql.connect(**config)
    try:
        reservations = query_counts_by_date(
            connection,
            """
            SELECT DATE(reservation_date) AS dt, COUNT(*) AS cnt
            FROM reservation
            WHERE DATE(reservation_date) >= %s AND DATE(reservation_date) <= %s
            GROUP BY DATE(reservation_date)
            """,
            start_date,
            end_date,
        )
        mentoring = query_counts_by_date(
            connection,
            """
            SELECT DATE(scheduled_at) AS dt, COUNT(*) AS cnt
            FROM mentoring_appointment
            WHERE DATE(scheduled_at) >= %s AND DATE(scheduled_at) <= %s
            GROUP BY DATE(scheduled_at)
            """,
            start_date,
            end_date,
        )
        mentoring_requests = query_counts_by_date(
            connection,
            """
            SELECT DATE(created_at) AS dt, COUNT(*) AS cnt
            FROM mentor_custom_request
            WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s
            GROUP BY DATE(created_at)
            """,
            start_date,
            end_date,
        )
        mentor_applications = query_counts_by_date(
            connection,
            """
            SELECT DATE(created_at) AS dt, COUNT(*) AS cnt
            FROM mentor_application
            WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s
            GROUP BY DATE(created_at)
            """,
            start_date,
            end_date,
        )
    finally:
        connection.close()

    mentoring = merge_counts(mentoring, mentoring_requests, mentor_applications)

    return (
        {date: reservations.get(date, 0) for date in dates},
        {date: mentoring.get(date, 0) for date in dates},
    )


def load_reservation_counts_from_csv(days: int = 30) -> dict[str, int]:
    counts = empty_counts(days)
    start = datetime.fromisoformat(next(iter(counts))).date()
    today = datetime.now().date()

    candidates = [
        ROOT_DIR / "data" / "dummy_reservations.csv",
        ROOT_DIR / "analytics" / "data" / "dummy_reservations.csv",
        Path.cwd() / "data" / "dummy_reservations.csv",
        Path.cwd() / "dummy_reservations.csv",
    ]
    csv_path = next((path for path in candidates if path.exists()), None)
    if csv_path is None:
        return counts

    with csv_path.open(newline="", encoding="utf-8") as file:
        reader = csv.DictReader(file)
        for row in reader:
            value = row.get("reservation_date")
            if not value:
                continue

            try:
                date = datetime.fromisoformat(value).date()
            except ValueError:
                continue

            if start <= date <= today:
                counts[date.isoformat()] = counts.get(date.isoformat(), 0) + 1

    return counts


@app.get("/")
async def root():
    return {"message": "Reservation Analytics Overview", "endpoint": "/api/analytics/overview"}


@app.get("/api/analytics/overview")
async def overview():
    days = 30
    dates = date_keys(days)

    try:
        database_counts = load_counts_from_database(days)
    except Exception:
        database_counts = None

    if database_counts:
        reservation_counts, mentoring_counts = database_counts
        if sum(reservation_counts.values()) == 0:
            reservation_counts = load_reservation_counts_from_csv(days)
            source = "database+csv"
        else:
            source = "database"
    else:
        reservation_counts = load_reservation_counts_from_csv(days)
        mentoring_counts = empty_counts(days)
        source = "csv"

    daily_stats = [
        {
            "date": date,
            "reservations": int(reservation_counts.get(date, 0)),
            "mentoring": int(mentoring_counts.get(date, 0)),
        }
        for date in dates
    ]

    last7 = daily_stats[-7:]
    reservation_trends = [
        {
            "day": datetime.fromisoformat(item["date"]).strftime("%a"),
            "approved": item["reservations"],
            "pending": 0,
        }
        for item in last7
    ]
    mentoring_trends = [
        {
            "day": datetime.fromisoformat(item["date"]).strftime("%a"),
            "appointments": item["mentoring"],
            "requests": 0,
        }
        for item in last7
    ]

    return {
        "source": source,
        "dailyStats": daily_stats,
        "reservationTrends": reservation_trends,
        "mentoringTrends": mentoring_trends,
    }
