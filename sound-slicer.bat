@echo off

set PHP=C:\php\php.exe
set SOX=%~dp0sox\sox.exe
set SCRIPT=%~dp0sound-slicer.php

@echo on
"%PHP%" "%SCRIPT%" %*