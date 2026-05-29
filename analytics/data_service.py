"""Reservation data: live MySQL when available, else demo CSV."""

from __future__ import annotations

from datetime import datetime, timedelta
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse
import os

import pandas as pd

ROOT_DIR = Path(__file__).resolve().parents[1]
ANALYTICS_STATUSES = ("Approved", "Pending", "Suggested")
COUNT_STATUSES = ("Approved", "Pending", "Suggested", "Rejected", "Cancelled")
DEFAULT_FACILITIES = [
    {"id": 1, "name": "CS Project Room", "capacity": 48},
    {"id": 2, "name": "Discussion Room 3", "capacity": 6},
    {"id": 3, "name": "Discussion Room 4", "capacity": 8},
    {"id": 4, "name": "Presentation Room 1", "capacity": 40},
    {"id": 5, "name": "Presentation Room 2", "capacity": 60},
    {"id": 6, "name": "COE Project Room", "capacity": 48},
    {"id": 7, "name": "Lounge Area", "capacity": 150},
]


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


def parse_mysql_url(database_url: str) -> dict | None:
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


def dummy_csv_paths() -> list[Path]:
    return [
        ROOT_DIR / "data" / "dummy_reservations.csv",
        ROOT_DIR / "analytics" / "data" / "dummy_reservations.csv",
        Path.cwd() / "data" / "dummy_reservations.csv",
    ]


def load_from_database() -> pd.DataFrame | None:
    try:
        import psycopg2
        from psycopg2.extras import RealDictCursor
    except ModuleNotFoundError:
        return None

    database_url = read_database_url()
    if not database_url:
        return None

    try:
        connection = psycopg2.connect(database_url, cursor_factory=RealDictCursor)
    except Exception:
        return None

    try:
        with connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT
                    r.id,
                    r.user_id,
                    r.facility_id,
                    f.name AS facility_name,
                    COALESCE(f.capacity, 0) AS facility_capacity,
                    r.event_name,
                    r.name AS requester_name,
                    r.email,
                    r.contact,
                    r.reservation_date,
                    r.reservation_start_time,
                    r.reservation_end_time,
                    r.capacity,
                    r.purpose,
                    r.status,
                    r.created_at,
                    r.updated_at,
                    r.rejection_reason
                FROM reservation r
                LEFT JOIN facility f ON f.id = r.facility_id
                WHERE r.status != 'AwaitingFacilitySelection'
                ORDER BY r.reservation_date ASC
                """
            )
            rows = cursor.fetchall()
            rows = [dict(row) for row in rows]
    except Exception:
        return None
    finally:
        connection.close()

    if not rows:
        return pd.DataFrame()

    df = pd.DataFrame(rows)
    df["reservation_date"] = pd.to_datetime(df["reservation_date"])
    df["created_at"] = pd.to_datetime(df["created_at"])
    df["updated_at"] = pd.to_datetime(df["updated_at"], errors="coerce")
    df["reservation_start_time"] = df["reservation_start_time"].astype(str)
    df["reservation_end_time"] = df["reservation_end_time"].astype(str)
    df["purpose"] = df["purpose"].fillna("General Reservation").replace("", "General Reservation")
    df["event_name"] = df["event_name"].fillna("Untitled Event")
    df["setup_date"] = df["created_at"]
    df["rso_letter_attached"] = df["purpose"].str.contains("RSO", case=False, na=False)
    return df


def load_facilities_from_database() -> list[dict]:
    try:
        import psycopg2
        from psycopg2.extras import RealDictCursor
    except ModuleNotFoundError:
        return []

    database_url = read_database_url()
    if not database_url:
        return []

    try:
        connection = psycopg2.connect(database_url, cursor_factory=RealDictCursor)
    except Exception:
        return []

    try:
        with connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT id, name, capacity
                FROM facility
                WHERE available_for_reservation = true
                ORDER BY name ASC
                """
            )
            rows = cursor.fetchall()
            rows = [dict(row) for row in rows]
    except Exception:
        return []
    finally:
        connection.close()

    return [
        {
            "id": int(row["id"]),
            "name": str(row["name"]),
            "capacity": int(row.get("capacity") or 0),
        }
        for row in rows
    ]


def load_from_csv() -> pd.DataFrame:
    csv_path = next((p for p in dummy_csv_paths() if p.exists()), None)
    if csv_path is None:
        return pd.DataFrame()

    df = pd.read_csv(csv_path)
    if df.empty:
        return df

    df["reservation_date"] = pd.to_datetime(df["reservation_date"])
    df["created_at"] = pd.to_datetime(df["created_at"], errors="coerce")
    if "updated_at" in df.columns:
        df["updated_at"] = pd.to_datetime(df["updated_at"], errors="coerce")
    if "setup_date" in df.columns:
        df["setup_date"] = pd.to_datetime(df["setup_date"], errors="coerce")
    else:
        df["setup_date"] = df["created_at"]

    df["facility_name"] = df.get("facility_name", "Unknown Facility").fillna("Unknown Facility")
    df["facility_capacity"] = pd.to_numeric(df.get("facility_capacity", df.get("capacity", 0)), errors="coerce").fillna(0)
    df["purpose"] = df.get("purpose", "General Reservation").fillna("General Reservation")
    df["event_name"] = df.get("name", df.get("event_name", "Event")).fillna("Event")
    df["rso_letter_attached"] = df.get("rso_letter_attached", False).fillna(False)
    return df


def resolve_dataframe() -> tuple[pd.DataFrame, str, int]:
    """Return (dataframe, source_label, live_count). source: live | demo"""
    live_df = load_from_database()
    live_count = 0 if live_df is None else len(live_df)

    if live_count > 0:
        return live_df.copy(), "live", live_count

    demo_df = load_from_csv()
    return demo_df.copy(), "demo", live_count


def analytics_dataframe(facility_id: int | None = None) -> tuple[pd.DataFrame, str, int]:
    df, source, live_count = resolve_dataframe()
    if df.empty:
        return df, source, live_count

    if facility_id is not None and "facility_id" in df.columns:
        df = df[df["facility_id"] == facility_id].copy()

    return df, source, live_count


def date_keys(days: int) -> list[str]:
    today = datetime.now().date()
    start = today - timedelta(days=days - 1)
    return [(start + timedelta(days=i)).isoformat() for i in range(days)]


def load_daily_overview(days: int = 30) -> dict:
    """Overview endpoint: reservations + mentoring counts per day."""
    dates = date_keys(days)
    start_date = dates[0]
    end_date = dates[-1]

    reservation_counts = {d: 0 for d in dates}
    mentoring_counts = {d: 0 for d in dates}
    source = "demo"

    try:
        import psycopg2
        from psycopg2.extras import RealDictCursor
    except ModuleNotFoundError:
        psycopg2 = None

    database_url = read_database_url()
    if psycopg2 and database_url:
        try:
            connection = psycopg2.connect(database_url, cursor_factory=RealDictCursor)
            try:
                with connection.cursor() as cursor:
                    cursor.execute(
                        """
                        SELECT CAST(reservation_date AS DATE) AS dt, COUNT(*) AS cnt
                        FROM reservation
                        WHERE status IN ('Approved','Pending','Suggested')
                          AND CAST(reservation_date AS DATE) BETWEEN %s AND %s
                        GROUP BY CAST(reservation_date AS DATE)
                        """,
                        (start_date, end_date),
                    )
                    for row in cursor.fetchall():
                        key = str(row["dt"])
                        if key in reservation_counts:
                            reservation_counts[key] = int(row["cnt"])

                    cursor.execute(
                        """
                        SELECT CAST(scheduled_at AS DATE) AS dt, COUNT(*) AS cnt
                        FROM mentoring_appointment
                        WHERE CAST(scheduled_at AS DATE) BETWEEN %s AND %s
                        GROUP BY CAST(scheduled_at AS DATE)
                        """,
                        (start_date, end_date),
                    )
                    for row in cursor.fetchall():
                        key = str(row["dt"])
                        if key in mentoring_counts:
                            mentoring_counts[key] = int(row["cnt"])
            finally:
                connection.close()
        except Exception:
            pass

    live_total = sum(reservation_counts.values())
    if live_total == 0:
        df = load_from_csv()
        if not df.empty:
            start = datetime.fromisoformat(start_date).date()
            today = datetime.now().date()
            for _, row in df.iterrows():
                d = row["reservation_date"].date()
                key = d.isoformat()
                if start <= d <= today and key in reservation_counts:
                    reservation_counts[key] += 1
        source = "demo" if live_total == 0 else "live"
    else:
        source = "live"

    daily_stats = [
        {
            "date": d,
            "reservations": reservation_counts[d],
            "mentoring": mentoring_counts[d],
        }
        for d in dates
    ]

    last7 = daily_stats[-7:]
    return {
        "source": source,
        "dataSourceLabel": "Live reservation data" if source == "live" else "Demo dataset (no live reservations yet)",
        "reservationCount": live_total,
        "dailyStats": daily_stats,
        "reservationTrends": [
            {
                "day": datetime.fromisoformat(i["date"]).strftime("%a"),
                "approved": i["reservations"],
                "pending": 0,
            }
            for i in last7
        ],
        "mentoringTrends": [
            {
                "day": datetime.fromisoformat(i["date"]).strftime("%a"),
                "appointments": i["mentoring"],
                "requests": 0,
            }
            for i in last7
        ],
    }
