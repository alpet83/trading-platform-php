#!/bin/sh
set -eu
container="trd-bots-hive"
echo "#INFO: opening log viewer in container $container"
exec docker exec -it "$container" php /app/src/cli/log_view.php "$@"
