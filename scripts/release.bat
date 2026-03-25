@echo off
setlocal

REM Usage:
REM   scripts\release.bat patch
REM   scripts\release.bat minor
REM   scripts\release.bat major
REM   scripts\release.bat 0.3.0

set ARG=%1
if "%ARG%"=="" set ARG=patch

if "%ARG%"=="patch" (
  powershell -ExecutionPolicy Bypass -File "%~dp0release.ps1" -Bump patch
  goto :eof
)
if "%ARG%"=="minor" (
  powershell -ExecutionPolicy Bypass -File "%~dp0release.ps1" -Bump minor
  goto :eof
)
if "%ARG%"=="major" (
  powershell -ExecutionPolicy Bypass -File "%~dp0release.ps1" -Bump major
  goto :eof
)

powershell -ExecutionPolicy Bypass -File "%~dp0release.ps1" -Version %ARG%

