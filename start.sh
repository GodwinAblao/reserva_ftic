#!/bin/bash
# Start both FastAPI analytics and Symfony on Railway

# Install Python dependencies first
cd analytics
pip install -r requirements.txt

# Start FastAPI with nohup to keep it running
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8002 > /tmp/fastapi.log 2>&1 &
FASTAPI_PID=$!

# Wait a moment for FastAPI to start
sleep 3

echo "FastAPI started on port 8002 (PID: $FASTAPI_PID)"

# Start Symfony PHP server in foreground (this keeps the container alive)
cd ..
php -S 0.0.0.0:$PORT -t public
