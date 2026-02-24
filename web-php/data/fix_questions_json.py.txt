#!/usr/bin/env python3
# fix_questions_json.py
# Склеює файл, де кілька JSON-масивів/об'єктів записані підряд, у 1 валідний JSON масив.

from __future__ import annotations
import json
import sys
from pathlib import Path
from typing import Any, List, Tuple, Optional


def _next_json_start(s: str, i: int) -> Optional[int]:
    """Знайти наступний символ, з якого потенційно починається JSON ('[' або '{')."""
    n = len(s)
    while i < n:
        ch = s[i]
        if ch in "[{":
            return i
        i += 1
    return None


def extract_json_values(text: str) -> List[Any]:
    """
    Дістає послідовно JSON-значення з тексту навіть якщо вони записані підряд:
    ][ або }{ або ]\n[ тощо.
    """
    dec = json.JSONDecoder()
    out: List[Any] = []
    i = 0
    n = len(text)

    while True:
        start = _next_json_start(text, i)
        if start is None:
            break

        try:
            val, end = dec.raw_decode(text, start)
            out.append(val)
            i = end
        except json.JSONDecodeError:
            # якщо в цій позиції не декодується — посунемось на 1 символ і спробуємо далі
            i = start + 1

        if i >= n:
            break

    return out


def merge_questions(values: List[Any]) -> Tuple[List[dict], List[str]]:
    """
    Об'єднує все в один список питань.
    Підтримує:
      - якщо value це list -> додає елементи
      - якщо value це dict -> додає як один елемент (на випадок, якщо раптом об’єкт)
    """
    merged: List[dict] = []
    warnings: List[str] = []

    for idx, v in enumerate(values, start=1):
        if isinstance(v, list):
            for item in v:
                if isinstance(item, dict):
                    merged.append(item)
                else:
                    warnings.append(f"Блок #{idx}: елемент масиву не є об'єктом (dict), пропускаю: {type(item).__name__}")
        elif isinstance(v, dict):
            merged.append(v)
        else:
            warnings.append(f"Блок #{idx}: кореневий JSON не list/dict, пропускаю: {type(v).__name__}")

    return merged, warnings


def validate_ids(items: List[dict]) -> List[str]:
    warnings: List[str] = []
    seen = {}
    for pos, q in enumerate(items, start=1):
        qid = q.get("id")
        if qid is None:
            warnings.append(f"Позиція {pos}: немає поля 'id'")
            continue
        if qid in seen:
            warnings.append(f"Дублікат id={qid} (позиції {seen[qid]} і {pos})")
        else:
            seen[qid] = pos
    return warnings


def main() -> int:
    if len(sys.argv) < 2:
        print("Використання: python fix_questions_json.py <input_file> [output_file]")
        return 2

    inp = Path(sys.argv[1])
    out = Path(sys.argv[2]) if len(sys.argv) >= 3 else inp.with_suffix(".fixed.json")

    raw = inp.read_text(encoding="utf-8", errors="replace")

    values = extract_json_values(raw)
    if not values:
        print("❌ Не знайшов жодного валідного JSON-блоку в файлі.")
        return 1

    merged, merge_warn = merge_questions(values)

    # якщо випадково отримали об'єкт виду {"questions":[...]} — залишимо як є
    # але у твоєму форматі зазвичай просто масив питань, тому merged -> список dict
    id_warn = validate_ids(merged)

    out.write_text(
        json.dumps(merged, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8"
    )

    print(f"✅ Знайдено JSON-блоків: {len(values)}")
    print(f"✅ Питань у фінальному масиві: {len(merged)}")
    print(f"✅ Збережено: {out}")

    if merge_warn or id_warn:
        print("\n⚠️ Попередження:")
        for w in merge_warn[:50]:
            print(" -", w)
        if len(merge_warn) > 50:
            print(f" - ... ще {len(merge_warn)-50} попереджень")
        for w in id_warn[:50]:
            print(" -", w)
        if len(id_warn) > 50:
            print(f" - ... ще {len(id_warn)-50} попереджень")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())