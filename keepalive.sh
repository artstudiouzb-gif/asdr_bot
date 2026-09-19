#!/bin/sh
# Держит режим слежения запущенным. Ставится в cron раз в 5 минут:
#   */5 * * * * /home/USER/asdr_bot/keepalive.sh >> /home/USER/asdr_bot/data/watch.log 2>&1
# Если процесс жив — скрипт молча выходит, если его убили — поднимает заново.
set -e
DIR=$(cd "$(dirname "$0")" && pwd)
PYTHON=${PYTHON:-$(command -v python3 || command -v python)}
cd "$DIR"
mkdir -p data
exec nohup "$PYTHON" -m asdr watch >> data/watch.log 2>&1
