"""
會議錄音轉逐字稿 API（純轉錄版，不含 diarization）
PHP 透過 http://127.0.0.1:8000/transcribe_diarize 呼叫
"""

import os
import gc
import tempfile
import traceback
from pathlib import Path

from fastapi import FastAPI, File, UploadFile, HTTPException
from fastapi.responses import JSONResponse
import torch

# 載入 .env
_env_path = Path(__file__).resolve().parent / ".env"
if _env_path.exists():
    from dotenv import load_dotenv
    load_dotenv(_env_path)

app = FastAPI(title="Meeting Transcribe API")

WHISPER_MODEL = os.getenv("WHISPER_MODEL", "base")
DEVICE = "cuda" if torch.cuda.is_available() else "cpu"
COMPUTE_TYPE = "float16" if DEVICE == "cuda" else "int8"

TEMP_DIR = Path(__file__).parent / "temp"
TEMP_DIR.mkdir(exist_ok=True)

_model = None


def get_model():
    global _model
    if _model is None:
        from faster_whisper import WhisperModel
        print(f"=== 載入模型: {WHISPER_MODEL} / {DEVICE} / {COMPUTE_TYPE} ===")
        _model = WhisperModel(
            WHISPER_MODEL,
            device=DEVICE,
            compute_type=COMPUTE_TYPE,
        )
    return _model


def format_timestamp(seconds: float) -> str:
    h = int(seconds // 3600)
    m = int((seconds % 3600) // 60)
    s = seconds % 60
    return f"{h:02d}:{m:02d}:{s:06.3f}"[:12]


def ensure_wav(audio_path: str) -> str:
    ext = Path(audio_path).suffix.lower()
    if ext in (".wav", ".wave"):
        return audio_path

    try:
        from pydub import AudioSegment
        seg = AudioSegment.from_file(audio_path)
        wav_path = str(Path(audio_path).with_suffix(".wav"))
        seg.export(wav_path, format="wav")
        return wav_path
    except Exception:
        return audio_path


@app.post("/transcribe_diarize")
async def transcribe_diarize(file: UploadFile = File(...)):
    if not file.filename:
        raise HTTPException(400, "無檔案名稱")

    ext = Path(file.filename).suffix.lower()
    allowed = {".wav", ".mp3", ".m4a", ".webm", ".ogg", ".flac", ".mp4"}
    if ext not in allowed:
        raise HTTPException(400, f"不支援格式 {ext}")

    tmp_path = None
    wav_path = None

    try:
        with tempfile.NamedTemporaryFile(delete=False, suffix=ext, dir=TEMP_DIR) as f:
            content = await file.read()
            f.write(content)
            tmp_path = f.name

        wav_path = ensure_wav(tmp_path)

        model = get_model()

        print("=== 開始轉錄 ===")
        segments, info = model.transcribe(
            wav_path,
            language="zh",
            beam_size=5,
            vad_filter=True,
        )

        segments_out = []
        speakers = set()
        full_text_parts = []

        for seg in segments:
            text = (seg.text or "").strip()
            if not text:
                continue

            start = float(seg.start or 0)
            end = float(seg.end or start)
            speaker = "SPEAKER_00"

            segments_out.append({
                "speaker": speaker,
                "start": round(start, 2),
                "end": round(end, 2),
                "text": text
            })
            speakers.add(speaker)
            full_text_parts.append(
                f"[{speaker}] {format_timestamp(start)} - {format_timestamp(end)}：{text}"
            )

        full_text = "\n".join(full_text_parts)

        print("=== 轉錄完成 ===")

        return JSONResponse({
            "success": True,
            "segments": segments_out,
            "full_text": full_text,
            "speakers": len(speakers),
            "raw_full_text": " ".join(s["text"] for s in segments_out)
        })

    except Exception as e:
        print("\n=== API ERROR ===")
        print(repr(e))
        traceback.print_exc()

        return JSONResponse(
            status_code=500,
            content={
                "success": False,
                "error": str(e),
                "segments": [],
                "full_text": "",
                "speakers": 0
            }
        )

    finally:
        for p in (tmp_path, wav_path):
            if p and os.path.exists(p):
                try:
                    os.unlink(p)
                except Exception:
                    pass

        gc.collect()
        if DEVICE == "cuda":
            torch.cuda.empty_cache()


@app.get("/health")
async def health():
    return {"status": "ok", "device": DEVICE}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8000)