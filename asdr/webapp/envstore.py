"""Чтение и безопасная запись .env из веб-панели.

Комментарии и незнакомые ключи сохраняются: меняются только переданные поля,
файл переписывается атомарно (во временный файл + rename).
"""

from __future__ import annotations

import os
import re
import tempfile
from pathlib import Path

# Поля, которые панель показывает и умеет менять.
EDITABLE_KEYS = (
    "SOURCES", "TARGET", "MODE", "BOT_TOKEN", "ADMIN_IDS", "WEB_SECRET",
    "BLOCKED_DOMAINS", "UNWRAP_BLOCKED_LINKS", "DROP_SUBSCRIBE_LINES", "DROP_HASHTAGS",
    "ADD_SOURCE_LINK", "INCLUDE_KEYWORDS", "EXCLUDE_KEYWORDS", "MIN_TEXT_LENGTH",
    "SKIP_WITHOUT_TEXT", "SKIP_MEDIA", "SKIP_FORWARDS", "SILENT",
    "POLL_LIMIT", "MAX_POSTS_PER_RUN", "MAX_RUNTIME", "DELAY_BETWEEN_POSTS",
    "BACKFILL_ON_FIRST_RUN",
)

# Значения, которые нельзя показывать в браузере целиком.
SECRET_KEYS = ("BOT_TOKEN", "API_HASH", "SESSION", "WEB_SECRET")

KEY_RE = re.compile(r"^([A-Z_][A-Z0-9_]*)\s*=")


def read_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip().strip("'\"")
    return values


def mask(value: str) -> str:
    if not value:
        return ""
    if len(value) <= 8:
        return "••••"
    return f"{value[:4]}••••{value[-4:]}"


def _format(key: str, value: str) -> str:
    value = (value or "").replace("\r", "").replace("\n", "\\n")
    return f"{key}={value}"


def write_env(path: Path, updates: dict[str, str]) -> None:
    """Обновляет только переданные ключи, остальное содержимое не трогает."""
    updates = {k: v for k, v in updates.items() if k}
    lines = path.read_text(encoding="utf-8").splitlines() if path.exists() else []
    seen: set[str] = set()
    out: list[str] = []

    for raw in lines:
        match = KEY_RE.match(raw.strip())
        key = match.group(1) if match else None
        if key and key in updates:
            if key in seen:
                continue  # дубликат ключа — выкидываем
            out.append(_format(key, updates[key]))
            seen.add(key)
        else:
            out.append(raw)

    missing = [k for k in updates if k not in seen]
    if missing:
        if out and out[-1].strip():
            out.append("")
        for key in missing:
            out.append(_format(key, updates[key]))

    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=str(path.parent), prefix=".env.", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write("\n".join(out).rstrip("\n") + "\n")
        os.replace(tmp, path)
        os.chmod(path, 0o600)  # в .env лежат токены — прячем от соседей по хостингу
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def read_text_file(path: Path) -> str:
    return path.read_text(encoding="utf-8") if path.exists() else ""


def write_text_file(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    normalized = (content or "").replace("\r\n", "\n").replace("\r", "\n").strip()
    path.write_text(normalized + "\n" if normalized else "", encoding="utf-8")
