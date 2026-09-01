@echo off
setlocal DISABLEDELAYEDEXPANSION

php "%~dp0baton" %*
exit /b %ERRORLEVEL%
