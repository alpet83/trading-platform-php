#!/bin/sh
set -eu
container="trd-bots-hive"
echo "#INFO: opening API-key injector in container $container"
exec docker exec -it "$container" php /app/src/cli/inject_apikey.php "$@"
