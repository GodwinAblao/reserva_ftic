import pandas as pd
import numpy as np
from datetime import datetime, timedelta
import random
import os

def generate_dummy_data():
    """Generate 100 dummy reservation data"""
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
    df.to_csv("data/dummy_reservations.csv", index=False)
    print(f"Generated {len(df)} dummy reservations")
    return df

if __name__ == "__main__":
    df = generate_dummy_data()
    print(f"✓ Generated {len(df)} dummy reservations")
    print(f"✓ Facilities: {', '.join(df['facility_name'].unique())}")
    print(f"✓ Data saved to: data/dummy_reservations.csv")