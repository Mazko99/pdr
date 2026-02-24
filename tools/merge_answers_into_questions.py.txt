import json
import re
import sys
from pathlib import Path


def normalize(s: str) -> str:
    return re.sub(r"\s+", " ", s.strip())


def parse_answers_with_txt(txt: str):
    """
    Очікує блоки вигляду приблизно:
    Питання 1. ...
    ...
    Правильна відповідь: 2
    Пояснення: ...
    (може бути кілька рядків пояснення)
    """
    lines = txt.splitlines()
    items = {}  # qnum -> {"correct_key": int, "explanation": str}
    i = 0

    qnum = None
    explanation_lines = []
    correct_key = None
    in_expl = False

    q_header_re = re.compile(r"^\s*(Питання|Питання№|Питання №)\s*(\d+)\s*[\.\:]-?\s*(.*)$", re.IGNORECASE)
    correct_re = re.compile(r"^\s*Правильна\s*відповідь\s*[:\-]\s*(\d+)\s*$", re.IGNORECASE)
    expl_re = re.compile(r"^\s*Пояснення\s*[:\-]\s*(.*)$", re.IGNORECASE)

    def flush():
        nonlocal qnum, correct_key, explanation_lines, in_expl
        if qnum is not None:
            expl = "\n".join([l.rstrip() for l in explanation_lines]).strip()
            items[int(qnum)] = {
                "correct_key": int(correct_key) if correct_key is not None else None,
                "explanation": expl if expl else None,
            }
        qnum = None
        correct_key = None
        explanation_lines = []
        in_expl = False

    while i < len(lines):
        line = lines[i]

        m = q_header_re.match(line)
        if m:
            # почався новий блок питання
            flush()
            qnum = m.group(2)
            in_expl = False
            i += 1
            continue

        m = correct_re.match(line)
        if m:
            correct_key = int(m.group(1))
            in_expl = False
            i += 1
            continue

        m = expl_re.match(line)
        if m:
            in_expl = True
            first = m.group(1).strip()
            if first:
                explanation_lines.append(first)
            i += 1
            continue

        # якщо ми в поясненні — збираємо до наступного "Питання N"
        if in_expl:
            explanation_lines.append(line)
        i += 1

    flush()
    return items


def main():
    if len(sys.argv) < 4:
        print("Usage: python merge_answers_into_questions.py <questions_export.json> <answers_with.txt> <out_questions.json>")
        sys.exit(1)

    questions_path = Path(sys.argv[1])
    answers_path = Path(sys.argv[2])
    out_path = Path(sys.argv[3])

    if not questions_path.exists():
        print(f"ERROR: not found {questions_path}")
        sys.exit(1)
    if not answers_path.exists():
        print(f"ERROR: not found {answers_path}")
        sys.exit(1)

    questions = json.loads(questions_path.read_text(encoding="utf-8"))
    if not isinstance(questions, list):
        print("ERROR: questions_export.json must be a JSON array")
        sys.exit(1)

    answers_txt = answers_path.read_text(encoding="utf-8", errors="ignore")
    parsed = parse_answers_with_txt(answers_txt)

    # map by id
    updated = 0
    missing = 0

    for q in questions:
        qid = q.get("id")
        if not isinstance(qid, int):
            continue

        # шукаємо Питання N де N == id
        info = parsed.get(qid)
        if not info:
            missing += 1
            continue

        ck = info.get("correct_key")
        expl = info.get("explanation")

        if ck is not None:
            q["correct_key"] = int(ck)

        if expl:
            q["explanation"] = expl

        # додаємо картинку за правилом n{ID}.png
        # якщо картинки немає — все одно додаємо шлях (потім просто покладеш файл)
        q["images"] = [f"/assets/questions/n{qid}.png"]

        updated += 1

    out_path.write_text(json.dumps(questions, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"OK. Updated: {updated}. Missing matches (no 'Питання {id}' in answers_with.txt): {missing}.")
    print(f"Output: {out_path}")


if __name__ == "__main__":
    main()
