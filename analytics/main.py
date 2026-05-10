from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
import pandas as pd
import numpy as np
from statsmodels.tsa.arima.model import ARIMA
from statsmodels.tsa.stattools import adfuller
import plotly.graph_objects as go
import plotly.express as px
from plotly.utils import PlotlyJSONEncoder
import json
from datetime import datetime, timedelta
import random
from typing import Dict, List, Any
import os

app = FastAPI(title="Reservation Analytics API", version="1.0.0")

# CORS middleware to allow requests from Symfony
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://127.0.0.1:8000"],  # Symfony dev server
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Load dummy data
def load_dummy_data() -> pd.DataFrame:
    """Load or generate dummy reservation data"""
    # Try multiple paths for the data file
    data_file = None
    for path in ["data/dummy_reservations.csv", "analytics/data/dummy_reservations.csv", "dummy_reservations.csv"]:
        if os.path.exists(path):
            data_file = path
            break

    if data_file and os.path.exists(data_file):
        df = pd.read_csv(data_file)
        df['reservation_date'] = pd.to_datetime(df['reservation_date'])
        df['created_at'] = pd.to_datetime(df['created_at'])
        if 'updated_at' in df.columns:
            df['updated_at'] = pd.to_datetime(df['updated_at'])
        if 'setup_date' in df.columns:
            df['setup_date'] = pd.to_datetime(df['setup_date'])
        return df

    # Generate 100 dummy reservations
    data = []
    facilities = [
        {"id": 1, "name": "CS Project Room", "capacity": 48},
        {"id": 2, "name": "Discussion Room 3", "capacity": 6},
        {"id": 3, "name": "Discussion Room 4", "capacity": 8},
        {"id": 4, "name": "Presentation Room 1", "capacity": 40},
        {"id": 5, "name": "Presentation Room 2", "capacity": 60},
        {"id": 6, "name": "COE Project Room", "capacity": 48},
        {"id": 7, "name": "Lounge Area", "capacity": 150}
    ]

    event_types = [
        "Workshop", "Seminar", "Conference", "Meeting", "Training",
        "Presentation", "Discussion", "Orientation", "RSO Event", "Academic Activity"
    ]

    purposes = [
        "Academic Discussion", "Professional Development", "Student Organization Meeting",
        "Research Presentation", "Career Guidance", "Skill Building Workshop",
        "Community Engagement", "Leadership Training", "Cultural Event", "Technical Training"
    ]

    statuses = ["Approved", "Pending", "Rejected", "Completed", "Cancelled"]

    start_date = datetime(2024, 1, 1)
    end_date = datetime(2026, 5, 10)

    for i in range(100):
        # Random date within range
        days_diff = (end_date - start_date).days
        random_days = random.randint(0, days_diff)
        reservation_date = start_date + timedelta(days=random_days)

        # Random facility
        facility = random.choice(facilities)

        # Random capacity (70-100% of facility capacity)
        capacity = random.randint(int(facility["capacity"] * 0.7), facility["capacity"])

        # Random times
        start_hour = random.randint(8, 16)  # 8 AM to 4 PM
        duration = random.randint(1, 4)  # 1-4 hours
        start_time = f"{start_hour:02d}:00:00"
        end_time = f"{(start_hour + duration):02d}:00:00"

        # Setup date (1-3 days before event)
        setup_days = random.randint(1, 3)
        setup_date = reservation_date - timedelta(days=setup_days)

        data.append({
            "id": i + 1,
            "user_id": random.randint(1, 50),  # Assume 50 users
            "facility_id": facility["id"],
            "facility_name": facility["name"],
            "suggested_facility_id": None,
            "name": f"{random.choice(event_types)} {i+1}",
            "email": f"user{i+1}@example.com",
            "contact": f"+63{random.randint(900000000, 999999999)}",
            "reservation_date": reservation_date,
            "reservation_start_time": start_time,
            "reservation_end_time": end_time,
            "capacity": capacity,
            "purpose": random.choice(purposes),
            "status": random.choice(statuses),
            "created_at": reservation_date - timedelta(days=random.randint(1, 30)),
            "updated_at": reservation_date,
            "rejection_reason": None if random.random() > 0.1 else "Schedule conflict",
            "setup_date": setup_date,
            "rso_letter_attached": random.choice([True, False]) if "RSO" in f"{random.choice(event_types)} {i+1}" else False
        })

    df = pd.DataFrame(data)
    os.makedirs("data", exist_ok=True)
    df.to_csv(data_file, index=False)
    return df

# Global data variable
reservations_df = load_dummy_data()

def arima_forecast(series: pd.Series, steps: int, frequency: str):
    """Fit ARIMA(1,1,1), return forecast values, dates, and simple accuracy indicators."""
    clean_series = series[series > 0]
    if len(clean_series) < 10:
        raise ValueError("Need at least 10 periods of data")

    model = ARIMA(clean_series, order=(1, 1, 1))
    model_fit = model.fit()
    forecast = model_fit.forecast(steps=steps)
    forecast_dates = pd.date_range(start=clean_series.index[-1], periods=steps + 1, freq=frequency)[1:]

    fitted = model_fit.fittedvalues.reindex(clean_series.index).dropna()
    comparable_actual = clean_series.reindex(fitted.index)
    errors = comparable_actual - fitted
    mae = float(np.mean(np.abs(errors))) if len(errors) else 0.0
    rmse = float(np.sqrt(np.mean(np.square(errors)))) if len(errors) else 0.0

    fig = go.Figure()
    fig.add_trace(go.Scatter(
        x=clean_series.index,
        y=clean_series.values,
        mode='lines',
        name='Historical'
    ))
    fig.add_trace(go.Scatter(
        x=forecast_dates,
        y=forecast,
        mode='lines',
        name='Forecast',
        line=dict(dash='dash')
    ))

    return fig, {
        "aic": float(model_fit.aic),
        "bic": float(model_fit.bic),
        "mae": mae,
        "rmse": rmse
    }

@app.get("/")
async def root():
    return {"message": "Reservation Analytics API", "version": "1.0.0"}

@app.get("/api/analytics/planning")
async def planning_analytics():
    """Planning: Demand forecasting and resource requirements"""
    try:
        # ARIMA forecasting for reservations per week
        weekly_data = reservations_df.groupby(pd.Grouper(key='reservation_date', freq='W')).size()
        weekly_fig, weekly_accuracy = arima_forecast(weekly_data, 8, 'W')
        weekly_fig.update_layout(title="Weekly Reservation Demand Forecast",
                                xaxis_title="Week", yaxis_title="Reservations")

        # ARIMA forecasting for reservations per month
        monthly_reservations = reservations_df.groupby(pd.Grouper(key='reservation_date', freq='ME')).size()
        monthly_fig, monthly_accuracy = arima_forecast(monthly_reservations, 6, 'ME')
        monthly_fig.update_layout(title="Monthly Reservation Demand Forecast",
                                 xaxis_title="Month", yaxis_title="Reservations")

        # Peak demand analysis
        hourly_demand = reservations_df.groupby(reservations_df['reservation_start_time'].str[:2]).size()
        peak_hours = hourly_demand.nlargest(3)

        # Room capacity analysis
        room_capacity = reservations_df.groupby('facility_name')['capacity'].agg(['mean', 'max', 'count'])

        # Participation trends
        monthly_participation = reservations_df.groupby(pd.Grouper(key='reservation_date', freq='ME'))['capacity'].sum()
        event_type_distribution = reservations_df['purpose'].value_counts()

        return {
            "forecast_chart": json.loads(json.dumps(weekly_fig, cls=PlotlyJSONEncoder)),
            "monthly_forecast_chart": json.loads(json.dumps(monthly_fig, cls=PlotlyJSONEncoder)),
            "peak_demand_hours": peak_hours.to_dict(),
            "recommended_room_capacity": room_capacity.to_dict(),
            "participation_trends": monthly_participation.to_dict(),
            "event_type_distribution": event_type_distribution.to_dict(),
            "forecast_accuracy": {
                "weekly_aic": weekly_accuracy["aic"],
                "weekly_bic": weekly_accuracy["bic"],
                "weekly_mae": weekly_accuracy["mae"],
                "weekly_rmse": weekly_accuracy["rmse"],
                "monthly_aic": monthly_accuracy["aic"],
                "monthly_bic": monthly_accuracy["bic"],
                "monthly_mae": monthly_accuracy["mae"],
                "monthly_rmse": monthly_accuracy["rmse"]
            }
        }

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/analytics/organizing")
async def organizing_analytics():
    """Organizing: Facility allocation and scheduling efficiency"""
    try:
        # Room utilization rates
        room_utilization = reservations_df.groupby('facility_name').agg({
            'id': 'count',
            'capacity': 'sum'
        }).rename(columns={'id': 'reservations', 'capacity': 'total_capacity'})

        facility_capacities = {
            "CS Project Room": 48,
            "Discussion Room 3": 6,
            "Discussion Room 4": 8,
            "Presentation Room 1": 40,
            "Presentation Room 2": 60,
            "COE Project Room": 48,
            "Lounge Area": 150,
        }
        room_utilization['available_capacity'] = [
            facility_capacities.get(facility_name, 0) * reservations
            for facility_name, reservations in room_utilization['reservations'].items()
        ]
        room_utilization['utilization_rate'] = np.where(
            room_utilization['available_capacity'] > 0,
            room_utilization['total_capacity'] / room_utilization['available_capacity'],
            0
        )

        # Overlapping reservations analysis
        reservations_df['date'] = reservations_df['reservation_date'].dt.date
        daily_reservations = reservations_df.groupby(['date', 'facility_name']).size().reset_index(name='count')
        overlapping_days = daily_reservations[daily_reservations['count'] > 1]

        # Facility load distribution
        facility_load = reservations_df['facility_name'].value_counts()
        peak_usage_times = reservations_df.groupby(reservations_df['reservation_start_time'].str[:2]).size()

        # Create utilization chart
        fig = px.bar(room_utilization, x=room_utilization.index, y='utilization_rate',
                    title="Room Utilization Rates")
        fig.update_layout(xaxis_title="Facility", yaxis_title="Utilization Rate")

        return {
            "utilization_chart": json.loads(json.dumps(fig, cls=PlotlyJSONEncoder)),
            "room_utilization": room_utilization.to_dict(orient="index"),
            "overlapping_reservations": len(overlapping_days),
            "facility_load_distribution": facility_load.to_dict(),
            "peak_usage_times": peak_usage_times.to_dict(),
            "optimization_suggestions": [
                "Consider expanding capacity for high-utilization rooms",
                "Implement booking limits for peak days",
                "Add buffer time between reservations"
            ]
        }

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/analytics/staffing")
async def staffing_analytics():
    """Staffing: Capacity requirements based on participation"""
    try:
        # Participation trends
        participation_trends = reservations_df.groupby(pd.Grouper(key='reservation_date', freq='ME'))['capacity'].agg(['sum', 'mean', 'max'])

        # Staff-to-participant ratios (assuming 1 staff per 20 participants)
        participation_trends['required_staff'] = (participation_trends['sum'] / 20).round()

        # High-demand periods
        daily_participation = reservations_df.groupby(reservations_df['reservation_date'].dt.date)['capacity'].sum()
        high_demand_days = daily_participation[daily_participation > daily_participation.quantile(0.8)]

        # Event type staffing needs
        event_staffing = reservations_df.groupby('purpose')['capacity'].agg(['mean', 'max'])

        # Create staffing chart
        fig = px.line(participation_trends, x=participation_trends.index, y='required_staff',
                     title="Monthly Staffing Requirements")
        fig.update_layout(xaxis_title="Month", yaxis_title="Required Staff")

        return {
            "staffing_chart": json.loads(json.dumps(fig, cls=PlotlyJSONEncoder)),
            "participation_trends": participation_trends.to_dict(),
            "participant_demand_trend": participation_trends['sum'].to_dict(),
            "high_demand_periods": high_demand_days.to_dict(),
            "event_type_staffing": event_staffing.to_dict(),
            "staffing_recommendations": [
                "Maintain 1:20 staff-to-participant ratio",
                "Schedule extra staff for high-demand periods",
                "Train staff for different event types"
            ]
        }

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/analytics/leading")
async def leading_analytics():
    """Leading: Event success metrics and RSO performance"""
    try:
        # Event completion rate
        status_counts = reservations_df['status'].value_counts()
        completion_rate = (status_counts.get('Completed', 0) / len(reservations_df)) * 100

        # RSO performance
        rso_events = reservations_df[reservations_df['rso_letter_attached'] == True]
        rso_completion_rate = (rso_events['status'].value_counts().get('Completed', 0) / len(rso_events)) * 100 if len(rso_events) > 0 else 0

        # Actual vs target participants (simulated)
        reservations_df['actual_participants'] = reservations_df['capacity'] * np.random.uniform(0.7, 1.1, len(reservations_df))
        participation_accuracy = (reservations_df['actual_participants'] / reservations_df['capacity']).mean() * 100

        # Event success by type
        event_success = reservations_df.groupby('purpose')['status'].apply(lambda x: (x == 'Completed').mean() * 100)

        # Create success metrics chart
        fig = px.bar(event_success, x=event_success.index, y=event_success.values,
                    title="Event Success Rate by Purpose")
        fig.update_layout(xaxis_title="Event Purpose", yaxis_title="Success Rate (%)")

        return {
            "success_chart": json.loads(json.dumps(fig, cls=PlotlyJSONEncoder)),
            "overall_completion_rate": completion_rate,
            "rso_completion_rate": rso_completion_rate,
            "participation_accuracy": participation_accuracy,
            "event_success_by_type": event_success.to_dict(),
            "leadership_insights": [
                "RSO events show higher completion rates with proper documentation",
                "Academic events have consistent participation",
                "Community events need better promotion"
            ]
        }

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/analytics/controlling")
async def controlling_analytics():
    """Controlling: Monitoring and quality assurance"""
    try:
        # Deviation from plans
        reservations_df['setup_gap'] = (reservations_df['reservation_date'] - reservations_df['setup_date']).dt.days
        avg_setup_gap = reservations_df['setup_gap'].mean()

        # No-show/cancellation proxy based on stored reservation outcomes.
        no_show_rate = reservations_df['status'].isin(['Cancelled', 'Rejected']).mean() * 100

        # Performance against targets
        target_achievement = reservations_df.groupby('status').size() / len(reservations_df) * 100

        # Date setup vs event date analysis
        setup_compliance = (reservations_df['setup_gap'] <= 3).mean() * 100

        # Rejection analysis
        rejection_reasons = reservations_df[reservations_df['rejection_reason'].notna()]['rejection_reason'].value_counts()

        facility_capacities = {
            "CS Project Room": 48,
            "Discussion Room 3": 6,
            "Discussion Room 4": 8,
            "Presentation Room 1": 40,
            "Presentation Room 2": 60,
            "COE Project Room": 48,
            "Lounge Area": 150,
        }
        utilization = reservations_df.groupby('facility_name').agg({
            'id': 'count',
            'capacity': 'sum'
        }).rename(columns={'id': 'reservations', 'capacity': 'total_capacity'})
        utilization['available_capacity'] = [
            facility_capacities.get(facility_name, 0) * reservations
            for facility_name, reservations in utilization['reservations'].items()
        ]
        utilization['utilization_rate'] = np.where(
            utilization['available_capacity'] > 0,
            utilization['total_capacity'] / utilization['available_capacity'],
            0
        )

        # Create control chart
        fig = px.histogram(reservations_df, x='setup_gap', title="Setup Date Compliance")
        fig.add_vline(x=3, line_dash="dash", line_color="red", annotation_text="Target: 3 days")
        fig.update_layout(xaxis_title="Days Between Setup and Event", yaxis_title="Frequency")

        return {
            "control_chart": json.loads(json.dumps(fig, cls=PlotlyJSONEncoder)),
            "average_setup_gap": avg_setup_gap,
            "no_show_rate": no_show_rate,
            "target_achievement": target_achievement.to_dict(),
            "setup_compliance_rate": setup_compliance,
            "rejection_analysis": rejection_reasons.to_dict(),
            "facility_utilization_rate": utilization['utilization_rate'].to_dict(),
            "control_recommendations": [
                "Implement automated reminders for setup dates",
                "Review rejection reasons to improve approval process",
                "Monitor no-show rates and implement penalties if needed"
            ]
        }

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8002)
