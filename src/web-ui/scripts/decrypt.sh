#!/bin/bash

scriptDir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd "${scriptDir}/.." || exit 1

ENV=$1

if [ -z "$ENV" ]; then
  echo "Error: ENV environment variable is not set or is empty."
  exit 1
fi

export SOPS_AGE_RECIPIENTS=$(cat .agekey.public)
export SOPS_AGE_KEY_FILE=$PWD/.agekey

echo "Trying decrypt from file $SOPS_AGE_KEY_FILE, check public keys is matched"

cat $SOPS_AGE_KEY_FILE | grep public && cat "$SOPS_AGE_KEY_FILE.public" 

ls -l $SOPS_AGE_KEY_FILE
sops -d --verbose --input-type yaml --output-type yaml --age ${SOPS_AGE_RECIPIENTS} .envs/${ENV}/secret.enc.yaml > .envs/${ENV}/secret-test.yaml
