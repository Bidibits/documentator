@echo off
rem Starts (or restarts) the Documentanator dev server in Docker at http://localhost:8100 and builds the page first.
rem PHP's own upload limits are raised so the 25 MB files the app accepts can get through.
rem   scripts\dev.cmd          start / restart
rem   docker stop documentator-dev     stop it
rem The archive rules run every night at 02:00 (office time) through the scheduler, which this starts too.
rem
rem Speed: the project sits on a slow Windows share, and every request used to re-read hundreds of Laravel files. So the
rem server keeps compiled PHP in memory (opcache) and keeps Laravel's config, routes and events cached INSIDE the container
rem (APP_*_CACHE point at /tmp, so the project folder and the tests are untouched). Controllers and views are still read
rem live, but after changing a route or a config file run scripts\dev.cmd again to pick it up.
set "PROJECT=%~dp0.."
docker stop documentator-dev >nul 2>&1
node "%~dp0build-app.mjs" || exit /b 1
docker run --rm -d --name documentator-dev -p 8100:8100 -e PHP_CLI_SERVER_WORKERS=4 -e APP_CONFIG_CACHE=/tmp/config.php -e APP_ROUTES_CACHE=/tmp/routes.php -e APP_EVENTS_CACHE=/tmp/events.php -v "%PROJECT%:/app" -w /app composer:2 sh -c "php artisan config:cache >/dev/null 2>&1; php artisan route:cache >/dev/null 2>&1; php artisan event:cache >/dev/null 2>&1; php artisan schedule:work >/dev/null 2>&1 & cd public && php -d opcache.enable_cli=1 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=2 -d upload_max_filesize=30M -d post_max_size=300M -d memory_limit=512M -S 0.0.0.0:8100 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
echo Documentanator is starting at http://localhost:8100
