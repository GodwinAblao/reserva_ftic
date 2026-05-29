#!/bin/bash
# Start both FastAPI analytics and Symfony on Railway

# Start FastAPI in background
cd analytics
python -m uvicorn app:app --host 0.0.0.0 --port 8002 &

# Start Symfony PHP server in foreground
cd ..
php -S 0.0.0.0:$PORT -t public
