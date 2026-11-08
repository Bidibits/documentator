@echo off
rem Loads (or, with --remove, takes away) the sample folders and documents (codes DEMO-...) with files of different kinds
rem and deadlines, so the app can be tried with something in it. Safe to run twice. Works on any copy of the app.
rem   scripts\load-demo.cmd              load them
rem   scripts\load-demo.cmd --remove     take exactly those away again
set "PROJECT=%~dp0.."
docker run --rm -v "%PROJECT%:/app" -w /app composer:2 php artisan documentator:demo %*
