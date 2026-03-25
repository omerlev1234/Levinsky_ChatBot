import os
import json
import time
import sqlite3
import hashlib
import threading
from datetime import datetime, timezone

from fastapi import FastAPI, Request
from pydantic import BaseModel, Field
from openai import OpenAI

# =========================
# App + OpenAI client
# =========================
app = FastAPI()

# Store your key in an environment variable (recommended):
# Windows:
#   setx OPENAI_API_KEY "sk-..."
# Then restart terminal.
client = OpenAI(
    api_key="SECRET_KEY")

# =========================
# Limits (server-side only)
# =========================
USER_LIMIT_24H = 3
USER_WINDOW_SECONDS = 24 * 60 * 60
MAX_CHARS = 500
GLOBAL_LIMIT_PER_DAY = 100  # total for ALL users

# Where to store usage + history
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, "chat_usage.sqlite3")

# Where to log interactions
LOG_PATH = os.path.join(BASE_DIR, "chat_interactions.jsonl")

_db_lock = threading.Lock()

# =========================
# Prompts + clinic strings
# =========================
OPENING_LINE = "שלום וברוכים הבאים למרפאת לוינסקי 🙂 כאן הצ'אטבוט של המרפאה. אם יש לכם בעיה ותרצו להתייעץ, אשמח לעזור לכם.\n"

triage_prompt = """
You are a sexual-health clinic navigator for educational triage only.
You do NOT diagnose and do NOT recommend medications or specific treatments.

Return ONLY valid JSON with EXACT keys:
- assistant_answer (string, in Hebrew)
- is_sexual_health_related (boolean)
- is_symptoms_present (boolean)
- is_exposure_risk_present (boolean)
- should_seek_care (boolean)
- care_urgency (string enum: EMERGENCY_NOW, URGENT_CLINIC_24_48H, CLINIC_SOON_3_7D, ROUTINE_TESTING, DONT_NEED_TO_COME_AT_ALL, SELF_CARE_INFO_ONLY, UNCLEAR_NEED_MORE_INFO)
- is_sensitive_case (boolean)
- sensitive_reasons (array of strings from: sexual_assault_or_coercion, minor_under_18, trafficking_or_exploitation, domestic_violence_threat, suicidal_ideation)
- safety_red_flags (array of strings from: high_fever, severe_pelvic_pain, severe_testicular_pain, fainting, pregnancy_with_symptoms, sexual_assault, suicidal_ideation, severe_allergic_reaction, uncontrolled_bleeding)
- confidence (number 0.0-1.0)
- user_gender (string enum: male, female, neutral, unknown)

The text you receive may contain sensitive content. Be empathetic and non-judgmental in your assistant_answer.

Rules:
1) If safety_red_flags is non-empty => care_urgency=EMERGENCY_NOW and should_seek_care=true.
2) If not sexual-health related => care_urgency=SELF_CARE_INFO_ONLY and should_seek_care=false.
3) is_sensitive_case MUST be true ONLY if the text clearly indicates at least one sensitive_reasons item.
   If uncertain, set is_sensitive_case=false and sensitive_reasons=[].
4) If is_sensitive_case=true then sensitive_reasons must be non-empty. Otherwise must be [].

assistant_answer rules:
- Write in Hebrew, friendly and non-judgmental.
- If is_sexual_health_related is false, assistant_answer MUST be an empty string "" (exactly).
- Give general explanation and safe next steps, but do NOT diagnose and do NOT recommend medications.
- BE EMPATHETIC and REASSURING.
- Keep it short (6-10 sentences).
- Do NOT include the care_urgency labels inside assistant_answer.
- End with one short disclaimer sentence: "המידע כאן כללי ואינו תחליף לייעוץ רפואי."

assistant_answer rules (additional):
- Use gendered Hebrew language that matches user_gender:
  * female → את / אותך / שלך
  * male → אתה / אותך / שלך
  * neutral or unknown → פנייה ניטרלית (אפשר "אפשר לשקול", בלי פנייה ישירה)

Gender rules:
- If the user explicitly refers to themselves in feminine form → user_gender=female
- If the user explicitly refers to themselves in masculine form → user_gender=male
- If unclear or mixed → user_gender=neutral
- NEVER guess gender from symptoms alone

Output JSON only; no extra keys, no extra text.
"""

CUSTOM_ANSWERS = {
    "LIMIT_REACHED": "הגעת למגבלת 3 ההודעות ל-24 שעות בערוץ הזה. אם יש תסמינים או אם יש דאגה מחשיפה, מומלץ לפנות לרופא/ה בקהילה או למרפאה לבריאות מינית.",
    "GLOBAL_LIMIT_REACHED": "נכון להיום השירות עמוס והגענו למגבלת הפניות היומית. אפשר לנסות שוב מחר. אם יש תסמינים חריגים או דאגה רפואית, מומלץ לפנות לרופא/ה או למוקד רפואי.",
    "SENSITIVE": "אני מצטער/ת שאת/ה מתמודד/ת עם זה. אם מדובר בכפייה/תקיפה או אם את/ה לא מרגיש/ה בטוח/ה, מומלץ לפנות לשירותי החירום או לארגון תמיכה אמין. אם יש סכנה מיידית - התקשר/י עכשיו למספר החירום.",
    "EMERGENCY_NOW": "נשמע שמדובר במצב שמצריך בדיקה מיידית. מומלץ לפנות עכשיו לקבלת טיפול רפואי דחוף (מוקד חירום/מיון). אם התסמינים חמורים או שיש סכנה מיידית - התקשר/י למספר החירום.",
    "URGENT_CLINIC_24_48H": "הכי בטוח להיבדק במרפאה בתוך 24-48 שעות, ניתן לקבוע תור באתר. אם מתפתח חום, כאב חזק, עילפון או החמרה מהירה - פנה/י לטיפול דחוף.",
    "CLINIC_SOON_3_7D": "מומלץ לשקול להגיע למרפאה בימים הקרובים, ניתן לקבוע תור באתר. אם התסמינים מחמירים או מתפתח כאב חזק או חום - פנה/י לטיפול דחוף.",
    "ROUTINE_TESTING": "גם אם אין תסמינים, ההמלצה היא לבצע בדיקות שגרתיות למחלות מין לאחר חשיפות מסוימות. אפשר לקבוע בדיקה דרך האתר. אם מופיעים תסמינים - פנה/י מוקדם יותר.",
    "DONT_NEED_TO_COME_AT_ALL": "לפי מה שנכתב, לא נראה שיש צורך להגיע למרפאה כרגע. אם יש דאגה או אם מופיעים תסמינים חדשים - אפשר לשקול בדיקות שגרתיות או לפנות לבדיקה.",
    "SELF_CARE_INFO_ONLY": "לפי מה שנכתב זה לא נשמע דחוף. אם עדיין יש דאגה, אפשר לעקוב אחרי התסמינים ולשקול בדיקות שגרתיות. אם מופיעים תסמינים או שיש החמרה - מומלץ לפנות לבדיקה.",
    "UNCLEAR_NEED_MORE_INFO": "כדי לעזור בצורה טובה יותר, צריך עוד פרטים: (1) האם יש תסמינים כרגע? (2) מתי הייתה החשיפה? (3) האם יש חום או כאב חזק?",
    "NOT_SEXUAL_HEALTH": "ממה שתואר, זה לא נשמע קשור לבריאות מינית. אם התכוונת לנושא של בריאות מינית, אפשר לפרט תסמינים או חשיפה אפשרית."
}

ALLOWED_SENSITIVE_REASONS = {
    "sexual_assault_or_coercion",
    "minor_under_18",
    "trafficking_or_exploitation",
    "domestic_violence_threat",
    "suicidal_ideation",
}

EMPATHY_MERGE_PROMPT = """
You are an empathetic Hebrew-speaking health assistant.

You will receive:
1) A clinical educational explanation (assistant_answer)
2) A short guidance message from the clinic (custom_answer)
3) user_gender (male/female/neutral/unknown)

Your task:
- Merge them into ONE single empathetic, natural Hebrew response.
- Keep it supportive, empathetic, calm, non-judgmental.
- Do NOT add medical diagnoses or medications.
- Do NOT contradict the guidance.
- If assistant_answer is empty, gently rephrase only the custom_answer.
- Do NOT restate the classification (e.g. sexual health related).
- Start directly with neutral factual context.
- Length: 6–8 sentences.
- End with exactly this sentence:
"המידע כאן כללי ואינו תחליף לייעוץ רפואי."

Rules:
- Match Hebrew gender to user_gender
- If neutral/unknown, avoid gendered verbs and pronouns

Return ONLY plain text in Hebrew. No JSON.
"""


# =========================
# DB init
# =========================
def init_db():
    with _db_lock:
        conn = sqlite3.connect(DB_PATH)
        cur = conn.cursor()

        cur.execute("""
        CREATE TABLE IF NOT EXISTS user_usage (
            user_key TEXT PRIMARY KEY,
            window_start INTEGER NOT NULL,
            count INTEGER NOT NULL
        )
        """)

        cur.execute("""
        CREATE TABLE IF NOT EXISTS global_usage (
            day TEXT PRIMARY KEY,
            count INTEGER NOT NULL
        )
        """)

        cur.execute("""
        CREATE TABLE IF NOT EXISTS user_history (
            user_key TEXT PRIMARY KEY,
            history_json TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )
        """)

        conn.commit()
        conn.close()


init_db()


# =========================
# Helpers
# =========================
def now_ts() -> int:
    return int(time.time())


def today_str_utc() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d")


def safe_json_loads(text: str, fallback):
    try:
        return json.loads(text)
    except Exception:
        return fallback


def log_event(payload: dict):
    try:
        payload = dict(payload)
        payload["logged_at"] = datetime.now(timezone.utc).isoformat()
        with open(LOG_PATH, "a", encoding="utf-8") as f:
            f.write(json.dumps(payload, ensure_ascii=False) + "\n")
    except Exception:
        # If logging fails, do not break chat.
        pass


def get_user_identity(request: Request) -> tuple[str, str, str]:
    """
    Returns (user_key, ip, user_agent)
    user_key is a stable hash derived from IP + User-Agent.
    NOTE: Behind WordPress proxy, you MUST forward X-Forwarded-For and User-Agent for accurate per-user limiting.
    """

    # Prefer proxy header if present
    xff = request.headers.get("x-forwarded-for", "").strip()
    if xff:
        # Take the first IP in the list
        ip = xff.split(",")[0].strip()
    else:
        ip = request.client.host if request.client else "unknown"

    ua = (request.headers.get("user-agent") or "").strip()
    if not ua:
        ua = "unknown"

    raw = (ip + "|" + ua).encode("utf-8", errors="ignore")
    user_key = hashlib.sha256(raw).hexdigest()[:24]
    return user_key, ip, ua


# =========================
# Usage checks
# =========================
def get_and_update_user_limit(user_key: str, allow_increment: bool) -> tuple[bool, int, int]:
    """
    Returns: (allowed, current_count, remaining)
    If allow_increment=True and allowed, increments count.
    Window resets after 24h from first message in the window.
    """
    ts = now_ts()

    with _db_lock:
        conn = sqlite3.connect(DB_PATH)
        cur = conn.cursor()

        cur.execute("SELECT window_start, count FROM user_usage WHERE user_key = ?", (user_key,))
        row = cur.fetchone()

        if not row:
            window_start = ts
            count = 0
            cur.execute(
                "INSERT INTO user_usage (user_key, window_start, count) VALUES (?, ?, ?)",
                (user_key, window_start, count),
            )
        else:
            window_start, count = int(row[0]), int(row[1])

        # Reset window if expired
        if ts - window_start >= USER_WINDOW_SECONDS:
            window_start = ts
            count = 0
            cur.execute(
                "UPDATE user_usage SET window_start = ?, count = ? WHERE user_key = ?",
                (window_start, count, user_key),
            )

        allowed = count < USER_LIMIT_24H
        if allowed and allow_increment:
            count += 1
            cur.execute(
                "UPDATE user_usage SET count = ? WHERE user_key = ?",
                (count, user_key),
            )

        conn.commit()
        conn.close()

    remaining = max(0, USER_LIMIT_24H - count)
    return allowed, count, remaining


def get_and_update_global_limit(allow_increment: bool) -> tuple[bool, int, int]:
    """
    Returns: (allowed, current_count_today, remaining_today)
    Resets every UTC day.
    """
    day = today_str_utc()

    with _db_lock:
        conn = sqlite3.connect(DB_PATH)
        cur = conn.cursor()

        cur.execute("SELECT count FROM global_usage WHERE day = ?", (day,))
        row = cur.fetchone()

        if not row:
            count = 0
            cur.execute(
                "INSERT INTO global_usage (day, count) VALUES (?, ?)",
                (day, count),
            )
        else:
            count = int(row[0])

        allowed = count < GLOBAL_LIMIT_PER_DAY
        if allowed and allow_increment:
            count += 1
            cur.execute("UPDATE global_usage SET count = ? WHERE day = ?", (count, day))

        conn.commit()
        conn.close()

    remaining = max(0, GLOBAL_LIMIT_PER_DAY - count)
    return allowed, count, remaining


def load_user_history(user_key: str) -> list[dict]:
    with _db_lock:
        conn = sqlite3.connect(DB_PATH)
        cur = conn.cursor()

        cur.execute("SELECT history_json FROM user_history WHERE user_key = ?", (user_key,))
        row = cur.fetchone()

        conn.close()

    if not row:
        return []

    data = safe_json_loads(row[0], fallback=[])
    if isinstance(data, list):
        return data
    return []


def save_user_history(user_key: str, history: list[dict]):
    trimmed = history[-2:] if isinstance(history, list) else []
    payload = json.dumps(trimmed, ensure_ascii=False)

    with _db_lock:
        conn = sqlite3.connect(DB_PATH)
        cur = conn.cursor()

        cur.execute("""
        INSERT INTO user_history (user_key, history_json, updated_at)
        VALUES (?, ?, ?)
        ON CONFLICT(user_key) DO UPDATE SET
            history_json = excluded.history_json,
            updated_at = excluded.updated_at
        """, (user_key, payload, now_ts()))

        conn.commit()
        conn.close()

def merge_empathic_answer(assistant_answer: str, custom_answer: str, user_gender: str) -> str:
    content = f"""
הסבר חינוכי:
{assistant_answer if assistant_answer else "[אין]"}

הנחיה מהמרפאה:
{custom_answer}

מגדר משתמש:
{user_gender}
""".strip()

    completion = client.chat.completions.create(
        model="gpt-4o",
        messages=[
            {"role": "system", "content": EMPATHY_MERGE_PROMPT},
            {"role": "user", "content": content},
        ],
        temperature=0.3,
    )

    return (completion.choices[0].message.content or "").strip()


def get_chat_and_triage(user_text: str) -> dict:
    completion = client.chat.completions.create(
        model="gpt-4o",
        messages=[
            {"role": "system", "content": triage_prompt},
            {"role": "user", "content": user_text},
        ],
        temperature=0,
        response_format={"type": "json_object"},
    )
    content = completion.choices[0].message.content
    if not content:
        raise ValueError("Empty model response")
    return json.loads(content)


def enforce_sensitive_gate(triage: dict) -> dict:
    reasons = triage.get("sensitive_reasons", [])
    if not isinstance(reasons, list):
        reasons = []
    valid = [r for r in reasons if r in ALLOWED_SENSITIVE_REASONS]
    triage["sensitive_reasons"] = valid
    triage["is_sensitive_case"] = (len(valid) > 0)
    return triage


def build_custom_response(triage: dict) -> str:
    # NOTE: We DO NOT check limit here anymore. Limits are enforced before calling OpenAI.
    if triage.get("is_sensitive_case") is True and triage.get("sensitive_reasons"):
        return CUSTOM_ANSWERS["SENSITIVE"]

    if triage.get("safety_red_flags") and len(triage["safety_red_flags"]) > 0:
        return CUSTOM_ANSWERS["EMERGENCY_NOW"]

    if triage.get("is_sexual_health_related") is False:
        return CUSTOM_ANSWERS["NOT_SEXUAL_HEALTH"]

    urgency = triage.get("care_urgency", "UNCLEAR_NEED_MORE_INFO")
    return CUSTOM_ANSWERS.get(urgency, CUSTOM_ANSWERS["UNCLEAR_NEED_MORE_INFO"])


def compose_final_answer(model_json: dict) -> tuple[str, str, dict]:
    """
    Returns:
      merged_answer (string shown to user)
      assistant_answer_for_history (store this)
      triage dict
    """
    assistant_answer = (model_json.get("assistant_answer") or "").strip()

    triage = dict(model_json)
    triage.pop("assistant_answer", None)

    triage = enforce_sensitive_gate(triage)
    custom = build_custom_response(triage)

    if triage.get("is_sexual_health_related") is False:
        assistant_answer = ""

    user_gender = triage.get("user_gender", "neutral") or "neutral"

    # Merge into one final empathetic message
    try:
        merged_answer = merge_empathic_answer(assistant_answer, custom, user_gender)
        if not merged_answer:
            raise ValueError("Empty merged answer")
    except Exception:
        # fallback
        if assistant_answer:
            merged_answer = f"{assistant_answer}\n\nהכוונה מהירה מהמרפאה: {custom}"
        else:
            merged_answer = f"הכוונה מהירה מהמרפאה: {custom}"

    assistant_answer_for_history = merged_answer
    return merged_answer, assistant_answer_for_history, triage


def build_context_user_text(current_question: str, history: list[dict], max_pairs: int = 2) -> str:
    prev = history[-max_pairs:] if history else []
    if not prev:
        return current_question.strip()

    parts = ["הקשר לשיחה (שאלות קודמות ותשובות):"]
    for idx, item in enumerate(prev, start=1):
        q = (item.get("q") or "").strip()
        a = (item.get("a") or "").strip()
        parts.append(f"שאלה {idx}: {q}")
        parts.append(f"תשובה {idx}: {a if a else '[לא ניתנה תשובה כי לא קשור לבריאות מינית]'}")
    parts.append("השאלה הנוכחית:")
    parts.append(current_question.strip())
    return "\n".join(parts)


# =========================
# Web API shapes
# =========================
class ChatRequest(BaseModel):
    message: str

    # Backward compatible: old clients may still send these.
    interactions_used: int = 0
    history: list[dict] = Field(default_factory=list)


class ChatResponse(BaseModel):
    reply: str


@app.post("/chat", response_model=ChatResponse)
async def chat(req: ChatRequest, request: Request):
    start = time.perf_counter()

    user_key, ip, ua = get_user_identity(request)
    msg = (req.message or "").strip()

    if len(msg) > MAX_CHARS:
        return {"reply": "ההודעה ארוכה מדי. אנא קצר/י אותה ונסו שוב."}

    if not msg:
        return {"reply": "אנא כתבו הודעה כדי שנוכל לעזור."}

    # 1) Global limit check (do NOT increment yet)
    global_allowed, global_count, global_remaining = get_and_update_global_limit(allow_increment=False)
    if not global_allowed:
        log_event({
            "event": "blocked_global_limit",
            "user_key": user_key,
            "ip": ip,
            "ua": ua[:120],
            "message": msg,
            "global_count": global_count,
            "global_remaining": global_remaining,
        })
        return {"reply": CUSTOM_ANSWERS["GLOBAL_LIMIT_REACHED"]}

    # 2) User 24h limit check (do NOT increment yet)
    user_allowed, user_count, user_remaining = get_and_update_user_limit(user_key, allow_increment=False)
    if not user_allowed:
        log_event({
            "event": "blocked_user_limit",
            "user_key": user_key,
            "ip": ip,
            "ua": ua[:120],
            "message": msg,
            "user_count": user_count,
            "user_remaining": user_remaining,
        })
        return {"reply": CUSTOM_ANSWERS["LIMIT_REACHED"]}

    # 3) Allowed -> load server-side history
    history = load_user_history(user_key)
    context_text = build_context_user_text(msg, history, max_pairs=2)

    # 4) Call OpenAI
    openai_ok = False
    error = None
    reply_text = "מצטער/ת, הייתה תקלה. נסו שוב בעוד רגע."

    try:
        model_json = get_chat_and_triage(context_text)
        reply_text, assistant_answer_for_history, triage = compose_final_answer(model_json)

        # 5) After successful completion -> increment counters
        get_and_update_user_limit(user_key, allow_increment=True)
        get_and_update_global_limit(allow_increment=True)

        # 6) Update history
        new_history = history + [{"q": msg, "a": assistant_answer_for_history}]
        save_user_history(user_key, new_history)

        openai_ok = True

        log_event({
            "event": "allowed_success",
            "user_key": user_key,
            "ip": ip,
            "ua": ua[:120],
            "message": msg,
            "reply_preview": reply_text[:300],
            "triage": triage,
            "latency_ms": int((time.perf_counter() - start) * 1000),
        })

    except Exception as e:
        error = str(e)
        log_event({
            "event": "allowed_error",
            "user_key": user_key,
            "ip": ip,
            "ua": ua[:120],
            "message": msg,
            "error": error,
            "latency_ms": int((time.perf_counter() - start) * 1000),
        })

    return {"reply": reply_text}


@app.get("/health")
def health():
    # Show basic status + today's counts
    day = today_str_utc()
    with _db_lock:
        conn = sqlite3.connect(DB_PATH)
        cur = conn.cursor()
        cur.execute("SELECT count FROM global_usage WHERE day = ?", (day,))
        row = cur.fetchone()
        conn.close()

    global_count = int(row[0]) if row else 0
    return {
        "ok": True,
        "utc_day": day,
        "global_used_today": global_count,
        "global_limit_per_day": GLOBAL_LIMIT_PER_DAY,
        "user_limit_24h": USER_LIMIT_24H,
    }
