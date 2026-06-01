@echo off
setlocal

cd /d "%~dp0"

set HOST=localhost
set PORT=8000
set URL=http://%HOST%:%PORT%

echo ========================================
echo   AI Study Helper - Local PHP Server
echo ========================================
echo.

where php >nul 2>nul
if errorlevel 1 (
    echo PHP tidak ditemukan di PATH.
    echo Install PHP atau tambahkan php.exe ke PATH terlebih dahulu.
    echo.
    pause
    exit /b 1
)

if not exist "vendor\autoload.php" (
    echo Folder vendor belum lengkap. Menjalankan composer install...
    where composer >nul 2>nul
    if errorlevel 1 (
        echo Composer tidak ditemukan di PATH.
        echo Jalankan manual: composer install
        echo.
        pause
        exit /b 1
    )
    composer install
    if errorlevel 1 (
        echo.
        echo composer install gagal.
        pause
        exit /b 1
    )
)

echo Server berjalan di %URL%
echo Tekan Ctrl+C untuk menghentikan server.
echo.

start "" "%URL%"
php -d upload_max_filesize=25M -d post_max_size=30M -d max_file_uploads=20 -S %HOST%:%PORT%

endlocal
