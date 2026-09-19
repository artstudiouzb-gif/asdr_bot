"""Мини-WSGI приложение: запуск обхода по HTTP.

Нужно там, где шаред-хостинг умеет только "cron по URL" (wget/curl на адрес)
или где стоит cPanel "Setup Python App" с Passenger.

Адреса:
    /            — короткая справка
    /run?key=... — запустить один проход (по умолчанию в фоне)
    /run?key=...&wait=1 — дождаться завершения и показать вывод
    /status?key=... — что уже опубликовано
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
from urllib.parse import parse_qs

from .config import BASE_DIR, Config


def _json(start_response, status: str, payload: dict):
    body = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
    start_response(status, [("Content-Type", "application/json; charset=utf-8"),
                            ("Content-Length", str(len(body)))])
    return [body]


def _run(args: list[str], wait: bool) -> dict:
    cmd = [sys.executable, "-m", "asdr", *args]
    env = {**os.environ, "PYTHONPATH": str(BASE_DIR)}
    if not wait:
        subprocess.Popen(
            cmd, cwd=str(BASE_DIR), env=env,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True,
        )
        return {"ok": True, "started": True}
    proc = subprocess.run(
        cmd, cwd=str(BASE_DIR), env=env, capture_output=True, text=True, timeout=600
    )
    return {
        "ok": proc.returncode == 0,
        "code": proc.returncode,
        "output": (proc.stdout or "") + (proc.stderr or ""),
    }


def application(environ, start_response):
    path = environ.get("PATH_INFO", "/").rstrip("/") or "/"
    query = parse_qs(environ.get("QUERY_STRING", ""))
    key = (query.get("key") or [""])[0]

    if path == "/":
        return _json(start_response, "200 OK", {
            "service": "asdr_bot",
            "endpoints": ["/run?key=WEB_SECRET", "/run?key=WEB_SECRET&wait=1", "/status?key=WEB_SECRET"],
        })

    try:
        cfg = Config.load()
    except SystemExit as exc:
        return _json(start_response, "500 Internal Server Error", {"ok": False, "error": str(exc)})

    if not cfg.web_secret or key != cfg.web_secret:
        return _json(start_response, "403 Forbidden", {"ok": False, "error": "неверный key"})

    wait = (query.get("wait") or ["0"])[0] in {"1", "true", "yes"}
    if path == "/run":
        try:
            return _json(start_response, "200 OK", _run(["poll"], wait))
        except subprocess.TimeoutExpired:
            return _json(start_response, "504 Gateway Timeout", {"ok": False, "error": "timeout"})
    if path == "/status":
        return _json(start_response, "200 OK", _run(["status"], True))

    return _json(start_response, "404 Not Found", {"ok": False, "error": "нет такого адреса"})
