"""Managerial analytics computations: ARIMA, aggregates, chart-ready JSON."""

from __future__ import annotations

from datetime import datetime, timedelta
import json
from typing import Any

import numpy as np
import pandas as pd
from statsmodels.tsa.arima.model import ARIMA

from data_service import (
    ANALYTICS_STATUSES,
    DEFAULT_FACILITIES,
    analytics_dataframe,
    load_facilities_from_database,
    resolve_dataframe,
)


def _approved_df(df: pd.DataFrame) -> pd.DataFrame:
    if df.empty:
        return df
    return df[df["status"].isin(ANALYTICS_STATUSES)].copy()


def _reservation_hours(df: pd.DataFrame) -> pd.Series:
    """Extract hour (00–23) from start time whether stored as str, time, or timedelta."""
    col = df["reservation_start_time"]
    if pd.api.types.is_datetime64_any_dtype(col):
        return col.dt.hour.astype(int).astype(str).str.zfill(2)
    as_str = col.astype(str)
    extracted = as_str.str.extract(r"(\d{1,2})")[0]
    return extracted.fillna("00").astype(int).astype(str).str.zfill(2)


def _top_events_dict(df: pd.DataFrame) -> dict[str, int]:
    if df.empty:
        return {}
    if "event_name" in df.columns and df["event_name"].notna().any():
        series = df["event_name"].fillna("Untitled Event")
    elif "name" in df.columns:
        series = df["name"].fillna("Untitled Event")
    else:
        return {}
    return {str(k): int(v) for k, v in series.value_counts().head(12).items()}


def _per_facility_chart_value(fc: dict[str, Any]) -> float:
    if fc.get("forecast"):
        return float(sum(fc["forecast"].values()))
    hist = fc.get("historical") or {}
    if hist:
        return float(list(hist.values())[-1])
    return 0.0


def _series_dict(series: pd.Series) -> dict[str, float]:
    out: dict[str, float] = {}
    for idx, val in series.items():
        if hasattr(idx, "strftime"):
            key = idx.strftime("%Y-%m-%d") if hasattr(idx, "day") and not hasattr(idx, "dayofweek") else str(idx)
        else:
            key = str(idx)
        out[key] = float(val) if pd.notna(val) else 0.0
    return out


def arima_forecast_series(
    counts: pd.Series,
    steps: int,
    freq: str,
    min_periods: int = 8,
) -> dict[str, Any]:
    clean = counts.astype(float)
    clean = clean[clean.index.notna()]
    if len(clean) < min_periods:
        return {
            "historical": _series_dict(clean),
            "forecast": {},
            "lower": {},
            "upper": {},
            "accuracy": {},
            "insufficient_data": True,
            "message": f"Need at least {min_periods} periods for ARIMA (have {len(clean)}).",
        }

    try:
        model = ARIMA(clean, order=(1, 1, 1))
        fit = model.fit()
        forecast = fit.forecast(steps=steps)
        conf = fit.get_forecast(steps=steps).conf_int(alpha=0.2)

        last_idx = clean.index[-1]
        forecast_index = pd.date_range(start=last_idx, periods=steps + 1, freq=freq)[1:]

        historical = _series_dict(clean)
        forecast_dict = {}
        lower_dict = {}
        upper_dict = {}
        for i, dt in enumerate(forecast_index):
            label = dt.strftime("%Y-%m") if freq in ("ME", "M") else dt.strftime("%Y-W%W")
            forecast_dict[label] = float(max(0, forecast.iloc[i]))
            lower_dict[label] = float(max(0, conf.iloc[i, 0]))
            upper_dict[label] = float(max(0, conf.iloc[i, 1]))

        fitted = fit.fittedvalues.reindex(clean.index).dropna()
        actual = clean.reindex(fitted.index)
        errors = actual - fitted
        mae = float(np.mean(np.abs(errors))) if len(errors) else 0.0
        rmse = float(np.sqrt(np.mean(np.square(errors)))) if len(errors) else 0.0

        return {
            "historical": historical,
            "forecast": forecast_dict,
            "lower": lower_dict,
            "upper": upper_dict,
            "accuracy": {
                "aic": round(float(fit.aic), 2),
                "bic": round(float(fit.bic), 2),
                "mae": round(mae, 2),
                "rmse": round(rmse, 2),
            },
            "insufficient_data": False,
            "message": "",
        }
    except Exception as exc:
        return {
            "historical": _series_dict(clean),
            "forecast": {},
            "lower": {},
            "upper": {},
            "accuracy": {},
            "insufficient_data": True,
            "message": str(exc),
        }


def weekly_counts(df: pd.DataFrame, facility_name: str | None = None) -> pd.Series:
    data = _approved_df(df)
    if facility_name:
        data = data[data["facility_name"] == facility_name]
    if data.empty:
        return pd.Series(dtype=float)
    return data.groupby(pd.Grouper(key="reservation_date", freq="W")).size()


def monthly_counts(df: pd.DataFrame, facility_name: str | None = None) -> pd.Series:
    data = _approved_df(df)
    if facility_name:
        data = data[data["facility_name"] == facility_name]
    if data.empty:
        return pd.Series(dtype=float)
    return data.groupby(pd.Grouper(key="reservation_date", freq="ME")).size()


def facility_list(df: pd.DataFrame) -> list[dict[str, Any]]:
    counts_by_id: dict[int, int] = {}
    counts_by_name: dict[str, int] = {}
    if not df.empty and "facility_name" in df.columns:
        grouped = (
            df.groupby(["facility_id", "facility_name"], dropna=False)
            .size()
            .reset_index(name="count")
        )
        for _, row in grouped.iterrows():
            count = int(row["count"])
            name = str(row["facility_name"])
            counts_by_name[name] = counts_by_name.get(name, 0) + count
            if pd.notna(row["facility_id"]):
                counts_by_id[int(row["facility_id"])] = counts_by_id.get(int(row["facility_id"]), 0) + count

    facilities = load_facilities_from_database() or DEFAULT_FACILITIES
    seen: set[tuple[int | None, str]] = set()
    items: list[dict[str, Any]] = []

    for facility in facilities:
        facility_id = facility.get("id")
        name = str(facility["name"])
        key = (int(facility_id) if facility_id is not None else None, name)
        seen.add(key)
        items.append(
            {
                "id": key[0],
                "name": name,
                "capacity": int(facility.get("capacity") or 0),
                "count": counts_by_id.get(key[0], counts_by_name.get(name, 0)) if key[0] is not None else counts_by_name.get(name, 0),
            }
        )

    for name, count in counts_by_name.items():
        matched = any(item["name"] == name for item in items)
        if not matched:
            items.append({"id": None, "name": name, "capacity": 0, "count": count})

    return sorted(items, key=lambda item: item["name"])


def meta_payload(df: pd.DataFrame, source: str, live_count: int) -> dict[str, Any]:
    return {
        "source": source,
        "dataSourceLabel": "Live reservation data" if source == "live" else "Demo dataset (no live reservations yet)",
        "reservationCount": live_count if source == "live" else len(df),
        "totalRows": len(df),
        "facilities": facility_list(df),
        "generatedAt": datetime.now().isoformat(timespec="seconds"),
    }


def planning_analytics(facility_id: int | None = None, facility_name: str | None = None, data_source: str = "auto") -> dict[str, Any]:
    df, source, live_count = analytics_dataframe(facility_id, data_source=data_source)
    meta = meta_payload(df, source, live_count)

    if facility_name is None and facility_id is not None and not df.empty:
        match = df[df["facility_id"] == facility_id]
        if not match.empty:
            facility_name = str(match.iloc[0]["facility_name"])

    weekly = arima_forecast_series(weekly_counts(df, facility_name), 8, "W")
    monthly = arima_forecast_series(monthly_counts(df, facility_name), 6, "ME")

    approved = _approved_df(df)
    hourly = approved.groupby(_reservation_hours(approved)).size() if not approved.empty else pd.Series(dtype=int)
    peak_hours = hourly.nlargest(8)

    room_capacity = (
        approved.groupby("facility_name")["capacity"].agg(["mean", "max", "count"])
        if not approved.empty
        else pd.DataFrame()
    )

    monthly_participation = (
        approved.groupby(pd.Grouper(key="reservation_date", freq="ME"))["capacity"].sum()
        if not approved.empty
        else pd.Series(dtype=float)
    )

    event_types = approved["purpose"].value_counts() if not approved.empty else pd.Series(dtype=int)

    per_facility_forecasts = []
    for fac in facility_list(df)[:12]:
        name = fac["name"]
        fc = arima_forecast_series(weekly_counts(df, name), 4, "W", min_periods=4)
        per_facility_forecasts.append(
            {
                "facility": name,
                "forecast": fc["forecast"],
                "historical": fc["historical"],
                "insufficient_data": fc["insufficient_data"],
                "chart_value": _per_facility_chart_value(fc),
            }
        )

    return {
        **meta,
        "forecast_series": {"weekly": weekly, "monthly": monthly},
        "per_facility_weekly": per_facility_forecasts,
        "peak_demand_hours": {f"{k}:00": int(v) for k, v in peak_hours.items()},
        "recommended_room_capacity": room_capacity.to_dict() if not room_capacity.empty else {},
        "participation_trends": _series_dict(monthly_participation),
        "event_type_distribution": {str(k): int(v) for k, v in event_types.items()},
        "forecast_accuracy": {
            "weekly_aic": weekly["accuracy"].get("aic"),
            "weekly_rmse": weekly["accuracy"].get("rmse"),
            "monthly_aic": monthly["accuracy"].get("aic"),
            "monthly_rmse": monthly["accuracy"].get("rmse"),
        },
        "selectedFacility": facility_name,
    }


def organizing_analytics(facility_id: int | None = None, data_source: str = "auto") -> dict[str, Any]:
    df, source, live_count = analytics_dataframe(facility_id, data_source=data_source)
    meta = meta_payload(df, source, live_count)
    approved = _approved_df(df)

    if approved.empty:
        return {
            **meta,
            "facility_load_distribution": {},
            "peak_usage_times": {},
            "peak_usage_heatmap": {"labels": [], "datasets": []},
            "room_utilization": {},
            "overlapping_reservations": 0,
            "optimization_suggestions": ["Submit reservations to populate organizing analytics."],
        }

    load = approved["facility_name"].value_counts()
    hours = _reservation_hours(approved)
    peak = approved.groupby(hours).size()

    approved = approved.copy()
    approved["hour"] = hours
    approved["date"] = approved["reservation_date"].dt.date
    approved["weekday"] = approved["reservation_date"].dt.day_name()
    heatmap = approved.groupby(["hour", "weekday"]).size().unstack(fill_value=0)
    weekday_order = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
    heatmap = heatmap.reindex(columns=[d for d in weekday_order if d in heatmap.columns])

    room_util = approved.groupby("facility_name").agg(
        reservations=("id", "count"),
        total_capacity=("capacity", "sum"),
        facility_capacity=("facility_capacity", "max"),
    )
    room_util["utilization_rate"] = np.where(
        room_util["facility_capacity"] > 0,
        (room_util["total_capacity"] / (room_util["facility_capacity"] * room_util["reservations"].clip(lower=1))).clip(0, 1.5),
        0,
    )

    daily = approved.groupby(["date", "facility_name"]).size().reset_index(name="count")
    overlaps = int((daily["count"] > 1).sum())

    return {
        **meta,
        "facility_load_distribution": {str(k): int(v) for k, v in load.items()},
        "peak_usage_times": {f"{k}:00": int(v) for k, v in peak.items()},
        "peak_usage_heatmap": {
            "hours": sorted(heatmap.index.astype(str).tolist(), key=lambda h: int(h) if str(h).isdigit() else 0),
            "days": [str(c) for c in heatmap.columns],
            "values": heatmap.values.tolist() if not heatmap.empty else [],
        },
        "room_utilization": {
            str(idx): {
                "reservations": int(row["reservations"]),
                "total_capacity": int(row["total_capacity"]),
                "utilization_rate": round(float(row["utilization_rate"]), 3),
            }
            for idx, row in room_util.iterrows()
        },
        "overlapping_reservations": overlaps,
        "optimization_suggestions": [
            "Balance load across facilities with lower utilization.",
            "Add buffer times during peak hours shown in the heatmap.",
            "Review facilities with same-day overlap counts.",
        ],
    }


def staffing_analytics(facility_id: int | None = None, data_source: str = "auto") -> dict[str, Any]:
    df, source, live_count = analytics_dataframe(facility_id, data_source=data_source)
    meta = meta_payload(df, source, live_count)
    approved = _approved_df(df)

    if approved.empty:
        return {**meta, "participation_trends": {}, "participant_demand_trend": {}, "high_demand_periods": {}}

    monthly = approved.groupby(pd.Grouper(key="reservation_date", freq="ME"))["capacity"].agg(["sum", "mean", "max"])
    monthly["required_staff"] = (monthly["sum"] / 20).round().astype(int)

    daily = approved.groupby(approved["reservation_date"].dt.date)["capacity"].sum()
    threshold = daily.quantile(0.8) if len(daily) > 2 else daily.max()
    high_demand = daily[daily >= threshold]

    trends = {
        str(idx.strftime("%Y-%m") if hasattr(idx, "strftime") else idx): {
            "sum": int(row["sum"]),
            "mean": round(float(row["mean"]), 1),
            "max": int(row["max"]),
            "required_staff": int(row["required_staff"]),
        }
        for idx, row in monthly.iterrows()
    }

    return {
        **meta,
        "participation_trends": trends,
        "participant_demand_trend": {k: v["sum"] for k, v in trends.items()},
        "high_demand_periods": {str(k): int(v) for k, v in high_demand.items()},
        "staffing_recommendations": [
            "Maintain approximately 1 staff member per 20 participants.",
            "Schedule additional support during high-demand periods.",
        ],
    }


def leading_analytics(facility_id: int | None = None, data_source: str = "auto") -> dict[str, Any]:
    df, source, live_count = analytics_dataframe(facility_id, data_source=data_source)
    meta = meta_payload(df, source, live_count)

    if df.empty:
        return {**meta, "overall_completion_rate": 0, "event_success_by_type": {}}

    status_counts = df["status"].value_counts()
    total = len(df)
    approved = int(status_counts.get("Approved", 0))
    completion_rate = round((approved / total) * 100, 1) if total else 0.0

    rso = df[df["rso_letter_attached"] == True] if "rso_letter_attached" in df.columns else pd.DataFrame()
    rso_rate = (
        round((rso["status"] == "Approved").mean() * 100, 1) if len(rso) else 0.0
    )

    success = (
        df.groupby("purpose")["status"]
        .apply(lambda s: round((s == "Approved").mean() * 100, 1))
        .sort_values(ascending=False)
    )

    return {
        **meta,
        "overall_completion_rate": completion_rate,
        "rso_completion_rate": rso_rate,
        "participation_accuracy": round(
            (_approved_df(df)["capacity"].mean() / max(_approved_df(df)["facility_capacity"].mean(), 1)) * 100,
            1,
        )
        if not _approved_df(df).empty
        else 0.0,
        "event_success_by_type": {str(k): float(v) for k, v in success.items()},
        "top_events": _top_events_dict(df),
    }


def controlling_analytics(facility_id: int | None = None, data_source: str = "auto") -> dict[str, Any]:
    df, source, live_count = analytics_dataframe(facility_id, data_source=data_source)
    meta = meta_payload(df, source, live_count)

    if df.empty:
        return {**meta, "average_setup_gap": 0, "no_show_rate": 0, "target_achievement": {}}

    work = df.copy()
    work["setup_gap"] = (work["reservation_date"] - work["setup_date"]).dt.days.abs()
    avg_gap = round(float(work["setup_gap"].mean()), 1)
    no_show = round(work["status"].isin(["Cancelled", "Rejected"]).mean() * 100, 1)
    target = (work["status"].value_counts() / len(work) * 100).round(1)
    compliance = round((work["setup_gap"] <= 3).mean() * 100, 1)
    rejections = work[work["rejection_reason"].notna()]["rejection_reason"].value_counts()

    util = _approved_df(work).groupby("facility_name").agg(
        reservations=("id", "count"),
        total_capacity=("capacity", "sum"),
        facility_capacity=("facility_capacity", "max"),
    )
    util["utilization_rate"] = np.where(
        util["facility_capacity"] > 0,
        util["total_capacity"] / (util["facility_capacity"] * util["reservations"].clip(lower=1)),
        0,
    )

    return {
        **meta,
        "average_setup_gap": avg_gap,
        "no_show_rate": no_show,
        "setup_compliance_rate": compliance,
        "target_achievement": {str(k): float(v) for k, v in target.items()},
        "rejection_analysis": {str(k): int(v) for k, v in rejections.items()},
        "facility_utilization_rate": {str(k): round(float(v), 3) for k, v in util["utilization_rate"].items()},
        "control_recommendations": [
            "Monitor setup lead time against the 3-day target.",
            "Track rejection reasons to improve approval workflow.",
            "Review facilities with low utilization for reallocation.",
        ],
    }


def export_weekly_forecast_csv(facility_id: int | None = None) -> str:
    data = planning_analytics(facility_id)
    lines = [
        "report_type,weekly_arima_forecast",
        f"data_source,{data.get('source', 'unknown')}",
        f"generated_at,{data.get('generatedAt', '')}",
        "week,actual,forecast,lower,upper",
    ]
    weekly = data.get("forecast_series", {}).get("weekly", {})
    hist = weekly.get("historical", {})
    fc = weekly.get("forecast", {})
    lower = weekly.get("lower", {})
    upper = weekly.get("upper", {})
    for week in sorted(set(list(hist.keys()) + list(fc.keys()))):
        lines.append(
            f"{week},{hist.get(week, '')},{fc.get(week, '')},{lower.get(week, '')},{upper.get(week, '')}"
        )
    return "\n".join(lines) + "\n"


def export_this_week_csv() -> str:
    df, source, _ = resolve_dataframe()

    today = datetime.now().date()
    week_start = today - timedelta(days=today.weekday())
    week_end = week_start + timedelta(days=6)

    lines = [
        "report_type,this_week_snapshot",
        f"data_source,{source}",
        f"week_start,{week_start}",
        f"week_end,{week_end}",
        "facility,approved_count,pending_count",
    ]

    if df.empty:
        lines.append("ALL,0,0")
        return "\n".join(lines) + "\n"

    mask = (df["reservation_date"].dt.date >= week_start) & (
        df["reservation_date"].dt.date <= week_end
    )
    week_df = df[mask]
    for name, grp in week_df.groupby("facility_name"):
        approved = int((grp["status"] == "Approved").sum())
        pending = int(grp["status"].isin(["Pending", "Suggested"]).sum())
        lines.append(f"{name},{approved},{pending}")

    return "\n".join(lines) + "\n"
