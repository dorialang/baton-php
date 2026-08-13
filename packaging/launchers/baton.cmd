@echo off
setlocal

for %%I in ("%~dp0..") do set "TOOLCHAIN_ROOT=%%~fI"
set "RUNTIME=%TOOLCHAIN_ROOT%\libexec\doria\php\bin\php.exe"
set "APPLICATION=%TOOLCHAIN_ROOT%\libexec\doria\baton.phar"

if not exist "%RUNTIME%" (
    >&2 echo baton: private PHP runtime is missing: "%RUNTIME%"
    exit /b 70
)

if not exist "%APPLICATION%" (
    >&2 echo baton: application archive is missing: "%APPLICATION%"
    exit /b 70
)

"%RUNTIME%" -n "%APPLICATION%" %*
exit /b %ERRORLEVEL%
