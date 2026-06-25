#!/bin/sh
set -e

php yii migrate --migrationPath=@app/modules/attentionMonitor/migrations --interactive=0

exec php yii serve 0.0.0.0:8080
