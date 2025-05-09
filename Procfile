web: php -S 0.0.0.0:8080 -t public
scheduler: bash -c "while true; do php artisan schedule:run --no-interaction; sleep 60; done"
