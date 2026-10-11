#!/bin/bash
# Swap a new SDE build into the site's database without blocking the site.
#
#   sudo bin/refresh-sde.sh [--drop-old] [DUMP]
#
# The site reads the `evesdeactive` schema. DUMP is a pg_dump of the jsonl-evesde pipeline's `evesde`
# schema (custom format), by default its latest published one (the latest-postgresschema.pgdump
# symlink). It is restored as-is, so it lands as `evesde` next to the live schema; nothing the site
# reads changes until the swap, two renames in one transaction:
#   evesdeactive -> evesdeprevious,  evesde -> evesdeactive
# evesdeprevious is kept so the swap can be undone; --drop-old removes it once the swap is done.
#
# Runs as root (uses sudo -u postgres: peer auth, no passwords). Refuses to run while the SDE
# pipeline is running, as the dump it would pick may still be being written.
set -euo pipefail

SITE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# The pipeline publishes each build here and points this symlink at the newest
LATEST_DUMP=/home/web/fuzzwork/htdocs/dump/latest-postgresschema.pgdump
PIPELINE_LOCK=/home/scripts/jsonl-evesde/.run-conversion.lock

ACTIVE=evesdeactive
STAGING=evesde          # the schema name inside the pipeline's dump
PREVIOUS=evesdeprevious

DROP_OLD=0
DUMP=""
for arg in "$@"; do
    case "$arg" in
        --drop-old) DROP_OLD=1 ;;
        -h|--help)  sed -n '2,14p' "$0"; exit 0 ;;
        -*)         echo "Unknown option: $arg" >&2; exit 1 ;;
        *)          DUMP="$arg" ;;
    esac
done

log() { echo "[$(date '+%H:%M:%S')] $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run as root (it uses sudo -u postgres)"

# Database and role from the site's .env
env_value() { grep -E "^$1=" "$SITE_DIR/.env" | tail -1 | cut -d= -f2- | tr -d "\"'"; }
DB_NAME=$(env_value DB_NAME)
DB_USER=$(env_value DB_USER)
[ -n "$DB_NAME" ] && [ -n "$DB_USER" ] || die "DB_NAME / DB_USER not found in $SITE_DIR/.env"

# Quiet NOTICEs: DROP SCHEMA ... CASCADE lists every table
psql_site() { sudo -u postgres env PGOPTIONS='-c client_min_messages=warning' psql -d "$DB_NAME" -v ON_ERROR_STOP=1 -qAt "$@"; }
schema_exists() { [ -n "$(psql_site -c "SELECT 1 FROM pg_namespace WHERE nspname = '$1'")" ]; }

# The pipeline holds an flock on this file while it runs
if [ -e "$PIPELINE_LOCK" ]; then
    exec 9<"$PIPELINE_LOCK"
    flock -n 9 || die "the SDE pipeline is running; wait for it to finish"
fi

if [ -z "$DUMP" ]; then
    DUMP=$(readlink -f "$LATEST_DUMP" || true)
    [ -n "$DUMP" ] && [ -e "$DUMP" ] || die "$LATEST_DUMP is missing or points nowhere; pass a dump path"
fi
[ -r "$DUMP" ] || die "can't read $DUMP"
LISTING=$(pg_restore -l "$DUMP") || die "$DUMP isn't a readable pg_dump archive"
grep -q "SCHEMA - $STAGING " <<<"$LISTING" || die "$DUMP has no '$STAGING' schema"
log "Dump: $DUMP"

schema_exists "$ACTIVE" || die "schema $ACTIVE doesn't exist in $DB_NAME: nothing to replace"
for leftover in "$STAGING" "$PREVIOUS"; do
    schema_exists "$leftover" && die "schema $leftover already exists in $DB_NAME (from an earlier run?). Drop it first:
  sudo -u postgres psql -d $DB_NAME -c 'DROP SCHEMA $leftover CASCADE'"
done

SWAPPED=0
cleanup() {
    # A failed run leaves the live schema untouched; remove the half-loaded copy
    if [ "$SWAPPED" -eq 0 ]; then
        psql_site -c "DROP SCHEMA IF EXISTS $STAGING CASCADE" 2>/dev/null || true
    fi
}
trap cleanup EXIT

log "Loading $STAGING into $DB_NAME (owned by $DB_USER)"
# pg_restore -n restores the schema's objects but not the schema itself
psql_site -c "CREATE SCHEMA $STAGING AUTHORIZATION \"$DB_USER\""
sudo -u postgres pg_restore -d "$DB_NAME" -n "$STAGING" --no-owner --no-privileges --role="$DB_USER" --exit-on-error < "$DUMP"
log "Analyzing $STAGING"
sudo -u postgres vacuumdb -q -d "$DB_NAME" --analyze-only --schema="$STAGING"

# Every table and column the live schema has must still be there, or site queries would break
MISSING=$(psql_site -c "
    SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = '$ACTIVE'
    EXCEPT
    SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = '$STAGING'
    ORDER BY 1")
[ -z "$MISSING" ] || die "the new build lacks tables/columns the live schema has; not swapping:
$MISSING"

NEW_TABLES=$(psql_site -c "
    SELECT tablename FROM pg_tables WHERE schemaname = '$STAGING'
    EXCEPT SELECT tablename FROM pg_tables WHERE schemaname = '$ACTIVE'
    ORDER BY 1")
NEW_COLUMNS=$(psql_site -c "
    SELECT n.table_name || '.' || n.column_name
    FROM information_schema.columns n
    JOIN pg_tables t ON t.schemaname = '$ACTIVE' AND t.tablename = n.table_name
    WHERE n.table_schema = '$STAGING'
      AND NOT EXISTS (SELECT 1 FROM information_schema.columns o WHERE o.table_schema = '$ACTIVE'
                      AND o.table_name = n.table_name AND o.column_name = n.column_name)
    ORDER BY 1")

log "Swapping schemas"
psql_site <<SQL
BEGIN;
ALTER SCHEMA $ACTIVE RENAME TO $PREVIOUS;
ALTER SCHEMA $STAGING RENAME TO $ACTIVE;
COMMIT;
SQL
SWAPPED=1

BUILD=$(psql_site -c "SELECT \"buildNumber\" FROM $ACTIVE.\"sdeInfo\" LIMIT 1" 2>/dev/null || echo "unknown")
log "Done: $DB_NAME.$ACTIVE is now SDE build $BUILD"
[ -z "$NEW_TABLES" ]  || log "New tables: $(echo "$NEW_TABLES" | paste -sd' ')"
[ -z "$NEW_COLUMNS" ] || log "New columns: $(echo "$NEW_COLUMNS" | paste -sd' ')"

if [ "$DROP_OLD" -eq 1 ]; then
    psql_site -c "DROP SCHEMA $PREVIOUS CASCADE"
    log "Dropped $PREVIOUS"
else
    log "Previous build kept as $PREVIOUS. To undo:"
    echo "  sudo -u postgres psql -d $DB_NAME -c 'BEGIN; ALTER SCHEMA $ACTIVE RENAME TO evesdefailed; ALTER SCHEMA $PREVIOUS RENAME TO $ACTIVE; COMMIT;'"
    log "Once the site looks right:"
    echo "  sudo -u postgres psql -d $DB_NAME -c 'DROP SCHEMA $PREVIOUS CASCADE'"
fi
