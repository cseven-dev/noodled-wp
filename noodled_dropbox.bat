@echo off
REM Launch the noodled drop-folder watcher with no console window.
REM Double-click to start, or register it to run at log on (see README):
REM   schtasks /create /tn "Noodled Drop" /tr "\"%~f0\"" /sc onlogon /rl limited /f
REM Uses an absolute pythonw path so it also works under Task Scheduler (which
REM does not always inherit your PATH). Falls back to PATH pythonw if missing.
set "PYW=C:\Python314\pythonw.exe"
if not exist "%PYW%" set "PYW=pythonw"
start "" /min "%PYW%" "%~dp0noodled_dropbox.py"
