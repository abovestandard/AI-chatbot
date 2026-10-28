#!/usr/bin/env python3
"""Lightweight sanity checks for documentation chunking and search logic."""

CHUNK_SIZE = 1500
CHUNK_OVERLAP = 200


def chunk_text(text):
    text = " ".join(text.split())
    if not text:
        return []
    chunks = []
    start = 0
    length = len(text)
    while start < length:
        chunk = text[start : start + CHUNK_SIZE].strip()
        if chunk:
            chunks.append(chunk)
        if start + CHUNK_SIZE >= length:
            break
        start += CHUNK_SIZE - CHUNK_OVERLAP
    return chunks


def search(question, docs, extra_terms=None, limit=5):
    stopwords = {
        "hi", "hey", "hello", "thanks", "thank", "you", "your", "please", "how", "are", "what",
        "hej", "hejsa", "tak", "hvordan", "går", "det", "du", "dig", "hvad", "med", "har", "man",
    }
    words = []
    for w in question.split():
        clean = w.strip(".,!?;:'\"–-")
        if len(clean) <= 2:
            continue
        if clean.lower() in stopwords:
            continue
        words.append(clean)

    terms = list(dict.fromkeys(words + (extra_terms or [])))
    if not terms:
        return []

    scores = []
    for doc in docs:
        for index, chunk in enumerate(doc["chunks"]):
            chunk_lower = chunk.lower()
            score = 0
            for term in terms:
                t = term.lower().strip()
                if len(t) <= 2:
                    continue
                if t in chunk_lower:
                    score += 3 if len(t) >= 5 else 1
            if score > 0:
                scores.append((score, doc["name"], chunk))

    scores.sort(key=lambda x: x[0], reverse=True)
    return [
        {"type": "documentation", "title": name, "excerpt": chunk[:2000]}
        for _, name, chunk in scores[:limit]
    ]


def test_chunking():
    text = "word " * 2000
    chunks = chunk_text(text)
    assert len(chunks) >= 2
    assert all(len(c) <= CHUNK_SIZE for c in chunks)
    print("chunking: OK")


def test_search():
    docs = [
        {
            "name": "tractor-manual.txt",
            "chunks": [
                "The New Holland T5 tractor has 120 horsepower and a 24-speed transmission.",
                "Workshop opening hours are Monday to Friday 08:00-16:00.",
            ],
        }
    ]
    results = search("What horsepower does the T5 tractor have?", docs)
    assert results
    assert "horsepower" in results[0]["excerpt"].lower()
    assert results[0]["title"] == "tractor-manual.txt"

    empty = search("hello how are you", docs)
    assert empty == []
    print("search: OK")


if __name__ == "__main__":
    test_chunking()
    test_search()
    print("All documentation logic tests passed.")
