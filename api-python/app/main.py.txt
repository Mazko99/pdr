from fastapi import FastAPI
from app.routers.health import router as health_router
from app.routers.auth import router as auth_router

app = FastAPI(title="EDKI API", version="0.1.0")

app.include_router(health_router)
app.include_router(auth_router)
