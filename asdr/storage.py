"""SQLite-хранилище: что уже переопубликовано и где остановились.

Нужно именно на шаред-хостинге: процесс живёт секунды, состояние между
запусками cron'а хранится в файле рядом с кодом.
"""

from __future__ import annotations

import hashlib
import os
import sqlite3
import time
from contextlib import contextmanager
from pathlib import Path

SCHEMA = """
CREATE TABLE IF NOT EXISTS posted (
    source     TEXT    NOT NULL,
    message_id INTEGER NOT NULL,
    target_id  INTEGER,
    body_hash  TEXT,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (source, message_id)
);
CREATE INDEX IF NOT EXISTS idx_posted_hash ON posted (body_hash);

CREATE TABLE IF NOT EXISTS cursor (
    source  TEXT PRIMARY KEY,
    last_id INTEGER NOT NULL
);
"""


def body_hash(text: str) -> str:
    normalized = " ".join((text or "").split()).lower()
    return hashlib.sha256(normalized.encode("utf-8")).hexdigest() if normalized else ""


class Storage:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self.conn = sqlite3.connect(path, timeout=30)
        self.conn.execute("PRAGMA journal_mode=WAL")
        self.conn.executescript(SCHEMA)
        self.conn.commit()

    # --- курсор по каналу ---------------------------------------------------
    def get_last_id(self, source: str) -> int:
        row = self.conn.execute("SELECT last_id FROM cursor WHERE source=?", (source,)).fetchone()
        return int(row[0]) if row else 0

    def set_last_id(self, source: str, last_id: int) -> None:
        self.conn.execute(
            "INSERT INTO cursor (source, last_id) VALUES (?, ?) "
            "ON CONFLICT(source) DO UPDATE SET last_id=excluded.last_id "
            "WHERE excluded.last_id > cursor.last_id",
            (source, int(last_id)),
        )
        self.conn.commit()

    # --- дедупликация -------------------------------------------------------
    def is_posted(self, source: str, message_id: int) -> bool:
        row = self.conn.execute(
            "SELECT 1 FROM posted WHERE source=? AND message_id=?", (source, int(message_id))
        ).fetchone()
        return row is not None

    def seen_hash(self, digest: str) -> bool:
        if not digest:
            return False
        row = self.conn.execute("SELECT 1 FROM posted WHERE body_hash=?", (digest,)).fetchone()
        return row is not None

    def mark_posted(self, source: str, message_id: int, target_id: int | None, digest: str = "") -> None:
        self.conn.execute(
            "INSERT OR REPLACE INTO posted (source, message_id, target_id, body_hash, created_at)"
            " VALUES (?, ?, ?, ?, ?)",
            (source, int(message_id), target_id, digest, int(time.time())),
        )
        self.conn.commit()

    def stats(self) -> dict:
        posted = self.conn.execute("SELECT COUNT(*) FROM posted").fetchone()[0]
        cursors = dict(self.conn.execute("SELECT source, last_id FROM cursor").fetchall())
        return {"posted": posted, "cursors": cursors}

    def close(self) -> None:
        try:
            self.conn.close()
        except sqlite3.Error:
            pass


class AlreadyRunning(RuntimeError):
    pass


@contextmanager
def single_instance(lock_path: Path, stale_after: int = 900):
    """Файловый лок: cron может запустить второй экземпляр поверх первого.

    fcntl доступен не везде, поэтому делаем переносимо — через O_EXCL + PID-файл
    с защитой от зависшего лока.
    """
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    if lock_path.exists():
        age = time.time() - lock_path.stat().st_mtime
        if age > stale_after:
            lock_path.unlink(missing_ok=True)
        else:
            raise AlreadyRunning(f"Уже выполняется (lock {lock_path}, возраст {int(age)}с)")
    try:
        fd = os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
    except FileExistsError as exc:
        raise AlreadyRunning(f"Уже выполняется (lock {lock_path})") from exc
    try:
        os.write(fd, str(os.getpid()).encode())
        os.close(fd)
        yield
    finally:
        lock_path.unlink(missing_ok=True)


def _process_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


@contextmanager
def daemon_lock(pid_path: Path):
    """Лок для долгоживущего процесса (`watch`): в файле pid, живость проверяется
    сигналом 0 — зависший файл от убитого процесса не блокирует запуск."""
    pid_path.parent.mkdir(parents=True, exist_ok=True)
    if pid_path.exists():
        try:
            old = int(pid_path.read_text().strip() or 0)
        except (ValueError, OSError):
            old = 0
        if _process_alive(old):
            raise AlreadyRunning(f"Слежение уже запущено (pid {old})")
        pid_path.unlink(missing_ok=True)
    pid_path.write_text(str(os.getpid()))
    try:
        yield
    finally:
        pid_path.unlink(missing_ok=True)
