from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    app_env: str = "dev"
    jwt_secret: str = "change_me"

    class Config:
        env_prefix = ""
        case_sensitive = False


settings = Settings()
