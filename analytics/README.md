# Reserva FTIC Analytics Setup

This document explains how to set up and run the advanced analytics system for the Reserva FTIC facility reservation management system.

## Overview

The analytics system consists of:
- **Symfony Application**: Main web application (PHP)
- **FastAPI Analytics Service**: Python-based analytics API with ARIMA forecasting
- **Dummy Data**: 100 sample reservations for testing and demonstration

## Architecture

```
Symfony App (Port 8000)
    └── /analytics page
        └── JavaScript calls FastAPI (Port 8002)
            └── ARIMA forecasting & managerial analytics
                └── Uses dummy data + future DB integration
```

## Setup Instructions

### 1. FastAPI Analytics Service

1. Navigate to the analytics directory:
   ```bash
   cd analytics
   ```

2. Install Python dependencies:
   ```bash
   pip install -r requirements.txt
   ```

3. Generate dummy data:
   ```bash
   python generate_data.py
   ```

4. Start the FastAPI server:
   ```bash
   python main.py
   ```

   The service will run on `http://127.0.0.1:8002`

### 2. Symfony Application

1. Start the Symfony development server:
   ```bash
   symfony server:start
   ```

   Or using PHP:
   ```bash
   php bin/console cache:clear
   php -S 127.0.0.1:8000 -t public
   ```

2. Access the analytics dashboard at: `http://127.0.0.1:8000/analytics`

## Features

### Managerial Functions

1. **Planning**: Demand forecasting using ARIMA, peak demand analysis, resource requirements
2. **Organizing**: Facility allocation, scheduling efficiency, utilization optimization
3. **Staffing**: Capacity requirements, staff-to-participant ratios, high-demand period identification
4. **Leading**: Event success metrics, RSO performance, participation accuracy
5. **Controlling**: Monitoring deviations, no-show rates, compliance tracking

### ARIMA Forecasting

- **Input**: Historical reservation data (weekly/monthly aggregations)
- **Output**: 8-week demand forecast with confidence intervals
- **Accuracy Metrics**: RMSE, MAE, AIC for model evaluation
- **Aggregation Levels**: Weekly (short-term) and monthly (long-term) patterns

## Data Structure

### Dummy Data Fields (100 records)
- `id`: Reservation ID
- `user_id`: User who made reservation
- `facility_id`: Facility reserved
- `facility_name`: Facility name
- `name`: Event name
- `email`: Contact email
- `contact`: Phone number
- `reservation_date`: Event date
- `reservation_start_time`: Start time
- `reservation_end_time`: End time
- `capacity`: Target participants
- `purpose`: Event objective/purpose
- `status`: Reservation status
- `created_at`: When reservation was made
- `setup_date`: Setup date (1-3 days before event)
- `rso_letter_attached`: Whether RSO letter was attached

## API Endpoints

- `GET /`: Service health check
- `GET /api/analytics/planning`: Planning analytics
- `GET /api/analytics/organizing`: Organizing analytics
- `GET /api/analytics/staffing`: Staffing analytics
- `GET /api/analytics/leading`: Leading analytics
- `GET /api/analytics/controlling`: Controlling analytics

## Database Integration

The system is designed to:
1. Start with 100 dummy reservations
2. Gradually integrate with live Symfony database
3. Update analytics as new reservations are added
4. Maintain historical data for accurate forecasting

## Future Enhancements

- Real-time database synchronization
- User authentication for analytics access
- Advanced visualization options
- Custom date range filtering
- Export capabilities for reports

## Troubleshooting

### FastAPI Service Issues
- Ensure port 8002 is available
- Check Python dependencies are installed
- Verify dummy data file exists in `data/` directory

### Symfony Integration Issues
- Confirm HttpClient service is available
- Check CORS settings allow requests to port 8002
- Verify analytics route is accessible

### Analytics Not Loading
- Check browser console for JavaScript errors
- Ensure FastAPI service is running
- Verify network connectivity between ports

## Development Notes

- Dummy data includes realistic patterns for ARIMA testing
- All analytics functions use the same dataset for consistency
- Charts are rendered using Chart.js for interactivity
- System is designed for easy extension to real database data