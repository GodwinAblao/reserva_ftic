"""Unified Reserva FTIC Analytics API — live/demo data, ARIMA, exports."""

from __future__ import annotations

from typing import Optional

from fastapi import FastAPI, Query
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import PlainTextResponse

from data_service import load_daily_overview
from engine import (
    controlling_analytics,
    export_this_week_csv,
    export_weekly_forecast_csv,
    leading_analytics,
    organizing_analytics,
    planning_analytics,
    staffing_analytics,
)

app = FastAPI(title="Reserva FTIC Analytics", version="2.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://127.0.0.1:8000",
        "http://localhost:8000",
    ],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/")
async def root():
    return {
        "message": "Reserva FTIC Analytics API",
        "version": "2.0.0",
        "endpoints": [
            "/api/analytics/overview",
            "/api/analytics/planning",
            "/api/analytics/organizing",
            "/api/analytics/staffing",
            "/api/analytics/leading",
            "/api/analytics/controlling",
            "/api/analytics/meta",
            "/api/analytics/export/weekly-forecast",
            "/api/analytics/export/this-week",
        ],
    }


@app.get("/api/analytics/overview")
async def overview(data_source: str = Query("auto")):
    return load_daily_overview(30, data_source=data_source)


@app.get("/api/analytics/meta")
async def meta(facility_id: Optional[int] = Query(None), data_source: str = Query("auto")):
    return planning_analytics(facility_id, data_source=data_source)


@app.get("/api/analytics/planning")
async def planning(facility_id: Optional[int] = Query(None), data_source: str = Query("auto")):
    return planning_analytics(facility_id, data_source=data_source)


@app.get("/api/analytics/organizing")
async def organizing(facility_id: Optional[int] = Query(None), data_source: str = Query("auto")):
    return organizing_analytics(facility_id, data_source=data_source)


@app.get("/api/analytics/staffing")
async def staffing(facility_id: Optional[int] = Query(None), data_source: str = Query("auto")):
    return staffing_analytics(facility_id, data_source=data_source)


@app.get("/api/analytics/leading")
async def leading(facility_id: Optional[int] = Query(None), data_source: str = Query("auto")):
    return leading_analytics(facility_id, data_source=data_source)


@app.get("/api/analytics/controlling")
async def controlling(facility_id: Optional[int] = Query(None), data_source: str = Query("auto")):
    return controlling_analytics(facility_id, data_source=data_source)


@app.get("/api/analytics/export/weekly-forecast")
async def export_weekly(
    facility_id: Optional[int] = Query(None),
):
    content = export_weekly_forecast_csv(facility_id)
    return PlainTextResponse(
        content,
        media_type="text/csv",
        headers={
            "Content-Disposition": 'attachment; filename="arima_weekly_forecast.csv"'
        },
    )


@app.get("/api/analytics/export/this-week")
async def export_this_week():
    content = export_this_week_csv()
    return PlainTextResponse(
        content,
        media_type="text/csv",
        headers={
            "Content-Disposition": 'attachment; filename="reservations_this_week.csv"'
        },
    )


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("app:app", host="127.0.0.1", port=8002, reload=False)
