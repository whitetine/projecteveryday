# 會議錄音轉逐字稿 API（WhisperX + 說話者分離）

本機 Python 服務，提供語音轉文字 + 說話者分離（diarization）+ 自動標點與斷句。

## 環境需求

- Python 3.9+
- FFmpeg（用於音檔格式轉換）
- （選用）NVIDIA GPU + CUDA 以加速

## 安裝

```bash
cd python_api
pip install -r requirements.txt
```

## HuggingFace Token（說話者分離必備）

**若未設定 HF_TOKEN，仍可轉錄，但不會有「誰說什麼話」的說話者分離。**

1. 至 https://huggingface.co/settings/tokens 建立 Token
2. 至 https://huggingface.co/pyannote/speaker-diarization-3.1 接受模型使用條款
3. 設定環境變數：`set HF_TOKEN=你的token`（Windows）或 `export HF_TOKEN=你的token`（Linux/Mac）

## 啟動

```bash
# Windows
set HF_TOKEN=你的token
cd python_api
uvicorn app:app --host 127.0.0.1 --port 8000

# Linux/Mac
export HF_TOKEN=你的token
cd python_api
uvicorn app:app --host 127.0.0.1 --port 8000
```

預設監聽 `http://127.0.0.1:8000`

## PHP 設定

在專案根目錄的 `.env` 或環境變數設定：

```
PYTHON_TRANSCRIBE_API_URL=http://127.0.0.1:8000
```

若未設定或 Python API 未啟動，PHP 會 fallback 至 OpenAI Whisper（無說話者分離、標點較少）。

## 資料庫

執行 `sql/alter_meetingrecordsdata_transcripts.sql`，在既有 `meetingrecordsdata` 表新增 `mr_segments_json`、`mr_speaker_count` 欄位。
