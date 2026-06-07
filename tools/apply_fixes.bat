@echo off

SET "MYSQL_PATH=c:\xampp\mysql\bin\mysql.exe"
SET "DB_HOST=127.0.0.1"
SET "DB_USER=root"
SET "DB_PASS=738155"
SET "DB_NAME=alghazali"
SET "SQL_FILE=comprehensive_fixes.sql"

IF NOT EXIST "%MYSQL_PATH%" (
    echo Error: MySQL executable not found at %MYSQL_PATH%
    exit /b 1
)

IF NOT EXIST "%SQL_FILE%" (
    echo Error: SQL file not found: %SQL_FILE%
    exit /b 1
)

rem Execute SQL file
"%MYSQL_PATH%" -h%DB_HOST% -u%DB_USER% -p%DB_PASS% %DB_NAME% < "%SQL_FILE%" > sql_apply_log.txt 2>&1

IF %ERRORLEVEL% NEQ 0 (
    echo Error applying SQL fixes.
) ELSE (
    echo SQL fixes applied successfully.
)
