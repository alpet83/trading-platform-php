#!/bin/sh
TELEGRAM_BOT_USERNAME=$(yq '.TELEGRAM_BOT_USERNAME' .envs/main/public.yaml)
bash scripts/run.sh "docker compose up"
