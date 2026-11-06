#!/bin/sh
# Entrypoint of db-app-replica (requirement 3, R3-4): a hot-standby,
# read-only copy of db-app kept up to date by streaming replication.
#   first start (empty volume): pg_basebackup clones db-app as the replicator
#                               role and writes standby.signal;
#   every start:                the stock postgres entrypoint starts the
#                               server, which replays db-app's WAL.
# The api sends its reads here (config/database.php read/write); writes are
# impossible on a standby (ERROR: cannot execute ... in a read-only transaction).
set -eu

: "${PRIMARY_HOST:?}" "${REPLICATION_USER:?}" "${REPLICATION_PASSWORD:?}"
export PGPASSWORD="$REPLICATION_PASSWORD"

if [ ! -s "$PGDATA/PG_VERSION" ]; then
  echo "db-app-replica: waiting for $PRIMARY_HOST"
  until gosu postgres pg_isready -q -h "$PRIMARY_HOST" -U "$REPLICATION_USER" -d postgres; do sleep 1; done

  echo "db-app-replica: cloning $PRIMARY_HOST (pg_basebackup)"
  mkdir -p "$PGDATA"
  chown postgres:postgres "$PGDATA"
  chmod 700 "$PGDATA"
  gosu postgres pg_basebackup -h "$PRIMARY_HOST" -U "$REPLICATION_USER" -D "$PGDATA" \
    --wal-method=stream --checkpoint=fast --write-recovery-conf
  # Explicit, named connection to the primary (seen in pg_stat_replication).
  printf "primary_conninfo = 'host=%s port=5432 user=%s password=%s application_name=db-app-replica'\n" \
    "$PRIMARY_HOST" "$REPLICATION_USER" "$REPLICATION_PASSWORD" >>"$PGDATA/postgresql.auto.conf"
fi

unset PGPASSWORD
exec docker-entrypoint.sh postgres -c hot_standby=on
