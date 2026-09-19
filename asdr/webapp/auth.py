"""Вход через Telegram Login Widget + подписанная cookie-сессия.

Проверка данных виджета — по официальному алгоритму: HMAC-SHA256 от строки
"key=value\\n..." с ключом sha256(bot_token). Ни пароли, ни сессия Telegram
при этом не передаются.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import time

COOKIE_NAME = "asdr_admin"
SESSION_TTL = 7 * 24 * 3600          # неделя
AUTH_MAX_AGE = 24 * 3600             # данные виджета старше суток не принимаем


class AuthError(Exception):
    pass


def secret_key(cfg) -> bytes:
    """Ключ подписи cookie. Стабилен между запусками, в открытом виде нигде не лежит."""
    base = (cfg.web_secret or "") + (cfg.bot_token or "") + (cfg.api_hash or "")
    if not base:
        base = os.urandom(32).hex()
    return hashlib.sha256(base.encode("utf-8")).digest()


# --- проверка данных Telegram Login Widget --------------------------------
def verify_telegram_auth(data: dict[str, str], bot_token: str) -> dict[str, str]:
    if not bot_token:
        raise AuthError("Не задан BOT_TOKEN — без него вход через Telegram невозможен")
    received = data.get("hash", "")
    if not received:
        raise AuthError("Нет подписи в данных Telegram")

    check = "\n".join(f"{k}={data[k]}" for k in sorted(data) if k != "hash")
    key = hashlib.sha256(bot_token.encode("utf-8")).digest()
    expected = hmac.new(key, check.encode("utf-8"), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, received):
        raise AuthError("Подпись Telegram не сошлась")

    try:
        auth_date = int(data.get("auth_date", "0"))
    except ValueError as exc:
        raise AuthError("Некорректная дата авторизации") from exc
    if time.time() - auth_date > AUTH_MAX_AGE:
        raise AuthError("Данные входа устарели, войдите заново")
    if not data.get("id"):
        raise AuthError("Telegram не передал id пользователя")
    return data


def is_admin(user_id: str | int, admin_ids: tuple[str, ...]) -> bool:
    return str(user_id) in {str(a).strip() for a in admin_ids if str(a).strip()}


# --- cookie-сессия ---------------------------------------------------------
def _sign(payload: bytes, key: bytes) -> str:
    sig = hmac.new(key, payload, hashlib.sha256).digest()
    return base64.urlsafe_b64encode(payload).decode() + "." + base64.urlsafe_b64encode(sig).decode()


def make_session(user: dict, key: bytes) -> str:
    payload = json.dumps(
        {
            "id": str(user.get("id")),
            "name": (user.get("first_name") or "") + (" " + user["last_name"] if user.get("last_name") else ""),
            "username": user.get("username") or "",
            "exp": int(time.time()) + SESSION_TTL,
        },
        ensure_ascii=False,
    ).encode("utf-8")
    return _sign(payload, key)


def read_session(cookie_value: str, key: bytes) -> dict | None:
    if not cookie_value or "." not in cookie_value:
        return None
    raw, _, sig = cookie_value.partition(".")
    try:
        payload = base64.urlsafe_b64decode(raw.encode())
        signature = base64.urlsafe_b64decode(sig.encode())
    except (ValueError, TypeError):
        return None
    if not hmac.compare_digest(hmac.new(key, payload, hashlib.sha256).digest(), signature):
        return None
    try:
        data = json.loads(payload)
    except json.JSONDecodeError:
        return None
    if int(data.get("exp", 0)) < time.time():
        return None
    return data


def csrf_token(session_cookie: str, key: bytes) -> str:
    return hmac.new(key, (session_cookie or "").encode() + b":csrf", hashlib.sha256).hexdigest()


def check_csrf(token: str, session_cookie: str, key: bytes) -> bool:
    if not token:
        return False
    # сравниваем байты: в присланном токене могут оказаться любые символы
    expected = csrf_token(session_cookie, key).encode("utf-8")
    return hmac.compare_digest(token.encode("utf-8", "replace"), expected)
