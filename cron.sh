#!/bin/sh
# Запуск по cron на шаред-хостинге. Пример строки в crontab (каждые 5 минут):
#   */5 * * * * /home/USER/asdr_bot/cron.sh >> /home/USER/asdr_bot/data/cron.log 2>&1
# Если своего python нет в PATH, укажите полный путь в PYTHON=.
set -e
DIR=$(cd "$(dirname "$0")" && pwd)
PYTHON=${PYTHON:-$(command -v python3 || command -v python)}
cd "$DIR"
exec "$PYTHON" -m asdr poll
