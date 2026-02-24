from fastapi import APIRouter, HTTPException
from jose import jwt
from datetime import datetime, timedelta, timezone

from app.schemas.auth import LoginRequest, TokenResponse
from app.core.config import settings

router = APIRouter(prefix="/auth", tags=["auth"])


@router.post("/login", response_model=TokenResponse)
def login(payload: LoginRequest):
    # TODO: замінити на перевірку користувача в БД
    if payload.password != "demo":
        raise HTTPException(status_code=401, detail="Invalid credentials")

    now = datetime.now(timezone.utc)
    exp = now + timedelta(hours=8)
    token = jwt.encode(
        {"sub": payload.email, "iat": int(now.timestamp()), "exp": int(exp.timestamp())},
        settings.jwt_secret,
        algorithm="HS256",
    )
    return TokenResponse(access_token=token)
