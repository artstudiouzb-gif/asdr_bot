"""Тесты веб-панели: вход через Telegram, CSRF, сохранение настроек.

Панель читает .env и patterns/* из каталога проекта, поэтому фикстура
подменяет эти файлы на время теста и возвращает исходные обратно.
"""

import hashlib
import hmac
import io
import re
import sys
import time
from pathlib import Path
from urllib.parse import urlencode

import pytest

BASE = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(BASE))

BOT_TOKEN = "123456:TESTTOKENTESTTOKEN"
ADMIN_ID = "111"
TEST_ENV = f"""API_ID=12345
API_HASH=deadbeefdeadbeefdeadbeefdeadbeef
SESSION=
SOURCES=shmirziyoyev
TARGET=@test_channel
BOT_TOKEN={BOT_TOKEN}
BOT_USERNAME=asr_repost_bot
ADMIN_IDS={ADMIN_ID}
WEB_SECRET=cronsecret
MODE=copy
"""
MANAGED = (".env", "patterns/footer.html", "patterns/drop_lines.txt", "patterns/remove.txt")


@pytest.fixture()
def app(tmp_path):
    saved = {name: (BASE / name).read_text(encoding="utf-8")
             for name in MANAGED if (BASE / name).exists()}
    (BASE / ".env").write_text(TEST_ENV, encoding="utf-8")
    (BASE / "patterns" / "footer.html").write_text(
        '<a href="https://asr.gov.uz/">website</a>\n', encoding="utf-8")

    for module in [m for m in list(sys.modules) if m.startswith("asdr")]:
        del sys.modules[module]
    from asdr.webapp import application

    try:
        yield Client(application)
    finally:
        for name in MANAGED:
            path = BASE / name
            if name in saved:
                path.write_text(saved[name], encoding="utf-8")
            elif path.exists():
                path.unlink()


class Client:
    """Минимальный WSGI-клиент с cookie."""

    def __init__(self, application):
        self.application = application
        self.cookies: dict[str, str] = {}

    def request(self, path, method="GET", qs="", form=None, with_cookie=True):
        body = urlencode(form or {}).encode()
        environ = {
            "PATH_INFO": path, "QUERY_STRING": qs, "REQUEST_METHOD": method,
            "wsgi.url_scheme": "http", "HTTP_HOST": "example.org",
            "wsgi.input": io.BytesIO(body), "CONTENT_LENGTH": str(len(body)),
        }
        if with_cookie and self.cookies:
            environ["HTTP_COOKIE"] = "; ".join(f"{k}={v}" for k, v in self.cookies.items())
        captured = {}

        def start_response(status, headers):
            captured["status"] = status
            captured["headers"] = headers

        chunks = self.application(environ, start_response)
        for name, value in captured["headers"]:
            if name == "Set-Cookie":
                key, _, rest = value.partition("=")
                self.cookies[key] = rest.split(";")[0]
        return (captured["status"], dict(captured["headers"]).get("Location", ""),
                b"".join(chunks).decode("utf-8"))

    def login(self, user_id=ADMIN_ID):
        return self.request("/auth", qs=telegram_query(user_id))

    def csrf(self):
        _, _, body = self.request("/admin")
        return re.search(r'name="csrf" value="([^"]+)"', body).group(1)


def telegram_query(user_id, token=BOT_TOKEN, age=0):
    data = {"id": str(user_id), "first_name": "Админ", "username": "admin",
            "auth_date": str(int(time.time()) - age)}
    check = "\n".join(f"{k}={data[k]}" for k in sorted(data))
    secret = hashlib.sha256(token.encode()).digest()
    data["hash"] = hmac.new(secret, check.encode(), hashlib.sha256).hexdigest()
    return urlencode(data)


def test_guest_sees_login_page(app):
    status, _, body = app.request("/")
    assert status.startswith("200")
    assert "telegram-widget.js" in body


def test_forged_signature_rejected(app):
    status, _, body = app.request("/auth", qs=telegram_query(ADMIN_ID, token="wrong:token"))
    assert status.startswith("403")
    assert "не сошлась" in body


def test_outdated_auth_rejected(app):
    status, _, _ = app.request("/auth", qs=telegram_query(ADMIN_ID, age=200_000))
    assert status.startswith("403")


def test_non_admin_sees_own_id(app):
    status, _, body = app.request("/auth", qs=telegram_query(999))
    assert status.startswith("403")
    assert "999" in body


def test_admin_login_and_panel(app):
    status, location, _ = app.login()
    assert status.startswith("303") and location == "/admin"
    status, _, body = app.request("/admin")
    assert status.startswith("200")
    assert "Каналы" in body and "Проверка очистки" in body


def test_post_without_csrf_rejected(app):
    app.login()
    status, _, _ = app.request("/admin", "POST", form={"csrf": "подделка", "SOURCES": "hack"})
    assert status.startswith("403")
    assert "SOURCES=shmirziyoyev\n" in (BASE / ".env").read_text(encoding="utf-8")


def test_saving_settings(app):
    app.login()
    status, location, _ = app.request("/admin", "POST", form={
        "csrf": app.csrf(), "SOURCES": "shmirziyoyev, @gov_uz", "TARGET": "@asr_news",
        "MODE": "copy", "ADMIN_IDS": "", "MIN_TEXT_LENGTH": "40", "POLL_LIMIT": "25",
        "MAX_POSTS_PER_RUN": "5", "MAX_RUNTIME": "90", "DELAY_BETWEEN_POSTS": "2.5",
        "BACKFILL_ON_FIRST_RUN": "0", "UNWRAP_BLOCKED_LINKS": "1", "DROP_SUBSCRIBE_LINES": "1",
        "FOOTER_HTML": '<a href="https://asr.gov.uz/">website</a>',
        "DROP_LINES": "^Фото:", "REMOVE_PATTERNS": "", "BOT_TOKEN_NEW": "", "WEB_SECRET_NEW": "",
    })
    assert status.startswith("303") and location.startswith("/admin?m=")

    env = (BASE / ".env").read_text(encoding="utf-8")
    assert "SOURCES=shmirziyoyev, @gov_uz" in env
    assert "TARGET=@asr_news" in env
    assert "API_HASH=deadbeef" in env            # чужие ключи не потеряны
    assert f"ADMIN_IDS={ADMIN_ID}" in env        # себя из админов не выкинуть
    assert f"BOT_TOKEN={BOT_TOKEN}" in env       # пустое поле не затирает токен
    assert "MIN_TEXT_LENGTH=40" in env and "DELAY_BETWEEN_POSTS=2.5" in env
    assert "^Фото:" in (BASE / "patterns" / "drop_lines.txt").read_text(encoding="utf-8")


def test_cleaning_preview(app):
    app.login()
    sample = ('Новость дня.\n\n<a href="https://president.uz/">Prezident.uz</a>|'
              '<a href="https://x.com/president_uz">X</a>')
    _, _, body = app.request("/admin/test", "POST", form={"csrf": app.csrf(), "sample": sample})
    preview = body.split("Показать результат")[1].split("</pre>")[0]
    assert "Новость дня." in preview
    assert "president.uz" not in preview        # подпись источника вырезана
    assert "asr.gov.uz" in preview              # своя подпись добавлена


def test_cron_url_requires_secret(app):
    status, _, _ = app.request("/run", qs="key=wrong", with_cookie=False)
    assert status.startswith("403")


def test_logout(app):
    app.login()
    status, _, _ = app.request("/logout", "POST")
    assert status.startswith("303")
    assert app.cookies.get("asdr_admin") == ""


def test_panel_offers_to_start_watching(app):
    app.login()
    _, _, body = app.request("/admin")
    assert "Включить слежение" in body
    assert "Слежение в реальном времени: остановлено" in body


def test_stop_watch_when_not_running(app):
    app.login()
    _, _, body = app.request("/admin/run", "POST", form={"csrf": app.csrf(), "action": "watch_stop"})
    assert "не запущено" in body
