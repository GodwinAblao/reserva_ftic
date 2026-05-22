"""Entry point — run: python main.py  or  uvicorn app:app --port 8002"""

from app import app

if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=8002)
