"""Командная строка: login / poll / watch / test / status / check."""

from __future__ import annotations

import argparse
import asyncio
import logging
import sys
from pathlib import Path

from .config import BASE_DIR, Config
from .storage import AlreadyRunning, Storage, daemon_lock, single_instance


def setup_logging(cfg: Config | None, verbose: bool = False) -> None:
    handlers: list[logging.Handler] = [logging.StreamHandler(sys.stdout)]
    if cfg:
        try:
            cfg.data_dir.mkdir(parents=True, exist_ok=True)
            handlers.append(logging.FileHandler(cfg.data_dir / "asdr.log", encoding="utf-8"))
        except OSError:
            pass
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=handlers,
        force=True,
    )


async def _run_poll(cfg: Config, *, dry_run: bool, limit: int | None) -> int:
    from .reposter import Reposter, build_client

    storage = Storage(cfg.db_path)
    client = build_client(cfg)
    await client.connect()
    reposter = Reposter(cfg, storage, client)
    try:
        await reposter.prepare()
        return await reposter.poll_once(dry_run=dry_run, limit=limit)
    finally:
        await reposter.close()
        storage.close()


def cmd_poll(args) -> int:
    """Один проход — то, что вызывает cron на шаред-хостинге."""
    cfg = Config.load()
    setup_logging(cfg, args.verbose)
    try:
        with single_instance(cfg.lock_path):
            published = asyncio.run(_run_poll(cfg, dry_run=args.dry_run, limit=args.limit))
    except AlreadyRunning as exc:
        logging.info("%s", exc)
        return 0
    logging.info("Готово, опубликовано постов: %s", published)
    return 0


async def _run_watch(cfg: Config, interval: int) -> None:
    from .reposter import Reposter, build_client

    storage = Storage(cfg.db_path)
    client = build_client(cfg)
    await client.connect()
    reposter = Reposter(cfg, storage, client)
    try:
        await reposter.prepare()
        await reposter.watch(safety_interval=interval)
    finally:
        await reposter.close()
        storage.close()


def cmd_watch(args) -> int:
    """Слежение в реальном времени: пост выходит сразу после публикации в источнике."""
    import time

    cfg = Config.load()
    setup_logging(cfg, args.verbose)
    try:
        with daemon_lock(cfg.watch_pid_path):
            attempt = 0
            while True:
                try:
                    asyncio.run(_run_watch(cfg, args.interval))
                    return 0
                except (KeyboardInterrupt, SystemExit):
                    return 0
                except Exception:  # noqa: BLE001 — демон переживает обрывы связи
                    attempt += 1
                    pause = min(300, 5 * 2 ** min(attempt, 6))
                    logging.exception("Слежение прервалось, перезапуск через %sс", pause)
                    time.sleep(pause)
    except AlreadyRunning as exc:
        logging.info("%s", exc)
        return 0


def cmd_login(args) -> int:
    """Интерактивный вход: печатает SESSION-строку для .env."""
    from telethon import TelegramClient
    from telethon.sessions import StringSession

    api_id, api_hash = Config.credentials()
    setup_logging(None, args.verbose)

    async def main() -> None:
        async with TelegramClient(StringSession(), api_id, api_hash) as client:
            me = await client.get_me()
            print("\nВошли как:", me.first_name, f"(@{me.username})" if me.username else "")
            print("\nSESSION (вставьте в .env одной строкой):\n")
            print(client.session.save())
            print()

    asyncio.run(main())
    return 0


def cmd_check(args) -> int:
    """Проверяет доступ к источникам и к целевому каналу."""
    from .reposter import build_client

    cfg = Config.load()
    setup_logging(cfg, args.verbose)

    async def main() -> int:
        client = build_client(cfg)
        await client.connect()
        try:
            if not await client.is_user_authorized():
                print("Сессия не авторизована: сначала `python -m asdr login`")
                return 1
            problems = 0
            for source in cfg.sources:
                try:
                    entity = await client.get_entity(source)
                    title = getattr(entity, "title", None) or getattr(entity, "username", source)
                    print(f"  источник OK: {source} -> {title}")
                except Exception as exc:  # noqa: BLE001
                    problems += 1
                    print(f"  источник ОШИБКА: {source} -> {exc}")
            try:
                entity = await client.get_entity(cfg.target)
                print(f"  цель OK: {cfg.target} -> {getattr(entity, 'title', cfg.target)}")
            except Exception as exc:  # noqa: BLE001
                problems += 1
                print(f"  цель ОШИБКА: {cfg.target} -> {exc}")
            if cfg.bot_token:
                from .reposter import build_client as bc

                bot = bc(cfg, bot=True)
                await bot.start(bot_token=cfg.bot_token)
                me = await bot.get_me()
                print(f"  бот OK: @{me.username} (должен быть админом целевого канала)")
                await bot.disconnect()
            return 1 if problems else 0
        finally:
            await client.disconnect()

    return asyncio.run(main())


def cmd_test(args) -> int:
    """Прогон правил очистки по файлу/stdin — без Telegram."""
    from .cleaner import clean_html

    cfg = Config.load()
    raw = Path(args.file).read_text(encoding="utf-8") if args.file else sys.stdin.read()
    print(clean_html(raw, cfg.rules))
    return 0


def cmd_status(args) -> int:
    cfg = Config.load()
    storage = Storage(cfg.db_path)
    stats = storage.stats()
    print(f"База: {cfg.db_path}")
    print(f"Опубликовано всего: {stats['posted']}")
    print("Позиции по каналам:")
    for source, last_id in stats["cursors"].items():
        print(f"  {source}: последний обработанный id {last_id}")
    storage.close()
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="python -m asdr", description="Telegram-репостер с очисткой подписей"
    )
    parser.add_argument("-v", "--verbose", action="store_true", help="подробный лог")
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("login", help="получить SESSION-строку")
    p.set_defaults(func=cmd_login)

    p = sub.add_parser("poll", help="один проход (для cron)")
    p.add_argument("--dry-run", action="store_true", help="показать результат, ничего не публикуя")
    p.add_argument("--limit", type=int, help="сколько сообщений тянуть из канала")
    p.set_defaults(func=cmd_poll)

    p = sub.add_parser("watch", help="слежение в реальном времени (демон)")
    p.add_argument("--interval", type=int, default=600,
                   help="период страховочного опроса, сек (на случай пропущенных апдейтов)")
    p.set_defaults(func=cmd_watch)

    p = sub.add_parser("check", help="проверить доступы")
    p.set_defaults(func=cmd_check)

    p = sub.add_parser("test", help="проверить очистку текста")
    p.add_argument("file", nargs="?", help="файл с HTML/текстом поста")
    p.set_defaults(func=cmd_test)

    p = sub.add_parser("status", help="что уже опубликовано")
    p.set_defaults(func=cmd_status)
    return parser


def main(argv: list[str] | None = None) -> int:
    sys.path.insert(0, str(BASE_DIR))
    args = build_parser().parse_args(argv)
    return args.func(args) or 0
