@echo off
chcp 65001 >nul
title WhisperX 語音轉錄 API
cd /d "%~dp0"

echo ========================================
echo   WhisperX 語音轉錄 API 啟動中...
echo ========================================
echo.
if exist .env (echo [OK] 已找到 .env 設定檔) else (echo [警告] 請建立 .env 並設定 HF_TOKEN)
echo.
echo 正在啟動服務於 http://127.0.0.1:8000
echo 請勿關閉此視窗，關閉後語音轉錄將無法使用 WhisperX
echo.
echo 按 Ctrl+C 可停止服務
echo ========================================

python -m uvicorn app:app --host 127.0.0.1 --port 8000
pause
