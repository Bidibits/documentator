@echo off
rem Starts (or restarts) the Digital Information Keeper dev server in Docker at http://localhost:8100 and builds the page first.
rem PHP's own upload limits are raised so the 25 MB files the app accepts can get through.
rem   scripts\dev.cmd          start / restart
rem   docker stop documentator-dev     stop it
rem The archive rules run every night at 02:00 (office time) through the scheduler, which this starts too.
set "PROJECT=%~dp0.."
docker stop documentator-dev >nul 2>&1
node "%~dp0build-app.mjs" || exit /b 1
docker run --rm -d --name documentator-dev -p 8100:8100 -e PHP_CLI_SERVER_WORKERS=4 -v "%PROJECT%:/app" -w /app composer:2 sh -c "php artisan schedule:work >/dev/null 2>&1 & cd public && php -d upload_max_filesize=30M -d post_max_size=300M -d memory_limit=512M -S 0.0.0.0:8100 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
echo Digital Information Keeper is starting at http://localhost:8100
