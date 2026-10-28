#!/usr/bin/env python3
"""Test improved PDF text extraction logic against a FlateDecode sample PDF."""

import re
import zlib
from pathlib import Path


def decompress_pdf_stream(data: bytes) -> bytes:
    data = data.lstrip(b"\x00\r\n ")
    for fn in (
        lambda d: zlib.decompress(d),
        lambda d: zlib.decompress(d, -zlib.MAX_WBITS),
        lambda d: zlib.decompress(d[2:], -zlib.MAX_WBITS) if len(d) > 2 else b"",
    ):
        try:
            out = fn(data)
            if out:
                return out
        except zlib.error:
            pass
    return b""


def parse_pdf_text_from_content(content: bytes) -> list[str]:
    parts = []
    text = content.decode("latin-1", errors="ignore")
    for match in re.findall(r"\(((?:[^\\()]|\\.)*)\)", text, re.S):
        parts.append(match.replace("\\(", "(").replace("\\)", ")"))
    for hex_match in re.findall(r"<([0-9A-Fa-f\\s]+)>", text):
        hex_clean = re.sub(r"\s+", "", hex_match)
        if len(hex_clean) % 2:
            hex_clean += "0"
        try:
            parts.append(bytes.fromhex(hex_clean).decode("utf-8", errors="ignore"))
        except ValueError:
            pass
    return parts


def extract_pdf_text(path: Path) -> str:
    raw = path.read_bytes()
    parts = parse_pdf_text_from_content(raw)
    for stream in re.findall(rb"stream\r?\n(.*?)\r?\nendstream", raw, re.S):
        decompressed = decompress_pdf_stream(stream)
        if decompressed:
            parts.extend(parse_pdf_text_from_content(decompressed))
    text = " ".join(p.strip() for p in parts if p.strip())
    return re.sub(r"\s+", " ", text).strip()


def main():
    pdf = Path("/tmp/test-flate.pdf")
    assert pdf.exists(), "missing test PDF"
    text = extract_pdf_text(pdf)
    assert "Robotplaeneklipper" in text, f"expected text in extraction, got: {text!r}"
    print("PDF extraction test passed:", text)


if __name__ == "__main__":
    main()
