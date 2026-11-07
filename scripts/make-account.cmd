@echo off
rem Makes a Documentator account from the command line, using Docker so PHP need not be installed.
rem It asks for the password itself (twice), so the password never goes through chat or the command line.
rem
rem   scripts\make-account.cmd admin "Administrator"     a new account
rem   scripts\make-account.cmd admin                     set a new password for an existing account
if "%~1"=="" (
  echo Usage: scripts\make-account.cmd username ["Full Name"]
  exit /b 1
)
set "PROJECT=%~dp0.."
if "%~2"=="" (
  docker run --rm -it -v "%PROJECT%:/app" -w /app composer:2 php artisan documentator:set-password %1
) else (
  docker run --rm -it -v "%PROJECT%:/app" -w /app composer:2 php artisan documentator:set-password %1 --create "--name=%~2"
)
