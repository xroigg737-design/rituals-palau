#!/bin/bash
# Daily backup of Rituals al Palau votes and logs
# Cron: 0 3 * * * /var/www/html/rituals-palau/api/backup.sh

DATA_DIR="/var/www/html/rituals-palau/api/data"
BACKUP_DIR="$DATA_DIR/backups"
DATE=$(date +%Y-%m-%d)
KEEP_DAYS=90

mkdir -p "$BACKUP_DIR"

cp "$DATA_DIR/votes.json" "$BACKUP_DIR/votes_${DATE}.json" 2>/dev/null
cp "$DATA_DIR/log.jsonl" "$BACKUP_DIR/log_${DATE}.jsonl" 2>/dev/null

find "$BACKUP_DIR" -name "votes_*.json" -mtime +$KEEP_DAYS -delete 2>/dev/null
find "$BACKUP_DIR" -name "log_*.jsonl" -mtime +$KEEP_DAYS -delete 2>/dev/null
