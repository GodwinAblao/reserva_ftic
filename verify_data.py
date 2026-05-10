import pandas as pd
import os

os.chdir('c:\\Users\\user\\reserva_ftic')
df = pd.read_csv('data/dummy_reservations.csv')

print('✓ Dataset Verification Complete!')
print(f'\nTotal Records: {len(df)}')
print(f'\nFacilities ({len(df["facility_name"].unique())}):')
for facility in sorted(df['facility_name'].unique()):
    print(f'  - {facility}')

print(f'\nDate Range: {df["reservation_date"].min()} to {df["reservation_date"].max()}')

print(f'\nCapacity Distribution by Facility:')
summary = df.groupby('facility_name')['capacity'].agg(['count', 'min', 'max', 'mean'])
for facility, row in summary.iterrows():
    print(f'  {facility}: {int(row["count"])} bookings, capacity {int(row["min"])}-{int(row["max"])} pax (avg: {row["mean"]:.1f})')
