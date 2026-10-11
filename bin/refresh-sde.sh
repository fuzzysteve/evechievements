#!/bin/bash
# Replace the site's evesde schema with a new SDE build, without blocking the site.
#
#   sudo bin/refresh-sde.sh [--drop-old] [DUMP]
#
# DUMP is a pg_dump of the `evesde` schema (custom format), by default the newest
# sdeyaml_postgresschema_*.pgdump from the jsonl-evesde pipeline. pg_restore can't restore into a
# different schema name, so the dump is restored into a throwaway database, renamed to evesde_new
# there, and loaded alongside the live schema. Nothing the site reads changes until the final swap:
# two schema renames in one transaction. The old schema is kept as evesde_old so the swap can be
# undone; --drop-old removes it once the swap is done.
#
# Runs as root (uses sudo -u postgres). Refuses to run while the SDE pipeline is running, as its
# newest dump may still be being written.
set -euo pipefail

SITE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUMP_DIR=/opt/sde/output
PIPELINE_LOCK=/home/scripts/jsonl-evesde/.run-conversion.lock

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

psql_site() { sudo -u postgres psql -d "$DB_NAME" -v ON_ERROR_STOP=1 -qAt "$@"; }

# The pipeline holds an flock on this file while it runs
if [ -e "$PIPELINE_LOCK" ]; then
    exec 9<"$PIPELINE_LOCK"
    flock -n 9 || die "the SDE pipeline is running; wait for it to finish"
fi

if [ -z "$DUMP" ]; then
    DUMP=$(ls -t "$DUMP_DIR"/sdeyaml_postgresschema_*.pgdump 2>/dev/null | head -1 || true)
    [ -n "$DUMP" ] || die "no sdeyaml_postgresschema_*.pgdump in $DUMP_DIR"
fi
[ -r "$DUMP" ] || die "can't read $DUMP"
pg_restore -l "$DUMP" >/dev/null || die "$DUMP isn't a readable pg_dump archive"
log "Dump: $DUMP"

for leftover in evesde_new evesde_old; do
    if [ -n "$(psql_site -c "SELECT 1 FROM pg_namespace WHERE nspname = '$leftover'")" ]; then
        die "schema $leftover already exists in $DB_NAME (from an earlier run?). Drop it first:
  sudo -u postgres psql -d $DB_NAME -c 'DROP SCHEMA $leftover CASCADE'"
    fi
done

STAGE_DB="evesde_stage_$$"
TMP_DUMP=$(mktemp /tmp/evesde_new.XXXXXX.pgdump)
SWAPPED=0
cleanup() {
    sudo -u postgres dropdb --if-exists "$STAGE_DB" 2>/dev/null || true
    rm -f "$TMP_DUMP"
    # A failed run leaves the live schema untouched; remove the half-loaded copy
    if [ "$SWAPPED" -eq 0 ]; then
        psql_site -c 'DROP SCHEMA IF EXISTS evesde_new CASCADE' 2>/dev/null || true
    fi
}
trap cleanup EXIT

log "Staging: restoring into $STAGE_DB and renaming to evesde_new"
sudo -u postgres createdb "$STAGE_DB"
sudo -u postgres pg_restore -d "$STAGE_DB" --no-owner --no-privileges --exit-on-error < "$DUMP"
sudo -u postgres psql -d "$STAGE_DB" -v ON_ERROR_STOP=1 -q -c 'ALTER SCHEMA evesde RENAME TO evesde_new'
sudo -u postgres pg_dump -d "$STAGE_DB" -n evesde_new -Fc > "$TMP_DUMP"
sudo -u postgres dropdb "$STAGE_DB"

log "Loading evesde_new into $DB_NAME (owned by $DB_USER)"
sudo -u postgres pg_restore -d "$DB_NAME" --no-owner --no-privileges --role="$DB_USER" --exit-on-error < "$TMP_DUMP"
log "Analyzing evesde_new"
sudo -u postgres vacuumdb -q -d "$DB_NAME" --analyze-only --schema=evesde_new

# Every table and column the live schema has must still be there, or site queries would break
MISSING=$(psql_site -c "
    SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = 'evesde'
    EXCEPT
    SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = 'evesde_new'
    ORDER BY 1")
if [ -n "$MISSING" ]; then
    die "the new build lacks tables/columns the live schema has; not swapping:
$MISSING"
fi
ADDED=$(psql_site -c "
    SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = 'evesde_new'
    EXCEPT
    SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = 'evesde'
    ORDER BY 1")

log "Swapping schemas"
psql_site <<'SQL'
BEGIN;
ALTER SCHEMA evesde RENAME TO evesde_old;
ALTER SCHEMA evesde_new RENAME TO evesde;
COMMIT;
SQL
SWAPPED=1

BUILD=$(psql_site -c 'SELECT "buildNumber" FROM evesde."sdeInfo" LIMIT 1' 2>/dev/null || echo "unknown")
log "Done: $DB_NAME.evesde is now SDE build $BUILD"
if [ -n "$ADDED" ]; then
    log "New columns: $(echo "$ADDED" | tr '\n' ' ')"
fi

if [ "$DROP_OLD" -eq 1 ]; then
    psql_site -c 'DROP SCHEMA evesde_old CASCADE'
    log "Dropped evesde_old"
else
    log "Previous schema kept as evesde_old. To undo:"
    echo "  sudo -u postgres psql -d $DB_NAME -c 'BEGIN; ALTER SCHEMA evesde RENAME TO evesde_failed; ALTER SCHEMA evesde_old RENAME TO evesde; COMMIT;'"
    log "Once the site looks right:"
    echo "  sudo -u postgres psql -d $DB_NAME -c 'DROP SCHEMA evesde_old CASCADE'"
fi
