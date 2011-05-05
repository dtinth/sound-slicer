@echo off

set PHP=C:\php\php.exe
set MIDICSV=%~dp0midicsv\Midicsv.exe
set CSVMIDI=%~dp0midicsv\Csvmidi.exe
set SCRIPT=%~dp0miditext.php

@echo on
"%PHP%" "%SCRIPT%" process_files_rhythmic %*