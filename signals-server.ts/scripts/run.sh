#!/bin/bash

scriptDir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd "${scriptDir}/.." || exit 1
export SOPS_AGE_RECIPIENTS=$(cat .agekey.public)

ENV=$(cat .current_instance)

if [ -z "$SOPS_AGE_KEY" ]; then
  if [ -f "$scriptDir/../.agekey" ]; then
    SOPS_AGE_KEY=$(cat $scriptDir/../.agekey)
  else
    echo "Error: SOPS_AGE_KEY environment variable is not set or is empty, and .agekey file does not exist."
    exit 1
  fi
fi


if [ -z "$ENV" ]; then
  echo "Error: ENV environment variable is not set or is empty."
  exit 1
fi

# Расшифровываем секретные данные, если файл существует
if [ -f ".envs/${ENV}/secret.enc.yaml" ]; then
  SECRET_DATA=$(echo "$SOPS_AGE_KEY" | SOPS_AGE_KEY=$(cat) sops -d --input-type yaml --output-type yaml --age ${SOPS_AGE_RECIPIENTS} .envs/${ENV}/secret.enc.yaml)
  echo $SECRET_DATA
else
  SECRET_DATA=""
fi

# Проверяем наличие файла public.yaml
PUBLIC_FILE=".envs/${ENV}/public.yaml"
if [ -f "$PUBLIC_FILE" ]; then
  PUBLIC_FILE_CONTENTS=$(cat "$PUBLIC_FILE")
else
  PUBLIC_FILE_CONTENTS=""
fi

# Объединяем файлы и собираем переменные окружения
COMMAN=$(yq ea -o p '. as $item ireduce ({}; . * $item )' <(echo "$SECRET_DATA") <(echo "$PUBLIC_FILE_CONTENTS") | awk '{gsub(/\./,"_",$1)}{print toupper($1)$2$3}')

# Экспортируем переменные окружения
export $COMMAN

# Запускаем переданную команду с этими переменными окружения
eval "$@"

# Проверка статуса выполнения команды docker-compose
if [ $? -ne 0 ]; then
  echo "Error: Command run failed."
  exit 1
fi
