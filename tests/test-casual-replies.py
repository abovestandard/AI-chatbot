#!/usr/bin/env python3
"""Sanity checks for greeting / small-talk reply logic (mirrors PHP)."""

DANISH_SIGNALS = [
    'hej', 'hejsa', 'davs', 'dav', 'godmorgen', 'godaften', 'godnat', 'farvel',
    'tak', 'hvordan', 'går', 'hvad', 'med', 'jeg', 'ikke', 'og', 'for', 'som',
    'til', 'kan', 'har', 'vores', 'pris', 'søg', 'mange', 'hilsen', 'jer', 'os',
]


def detect_lang(question: str) -> str:
    q = question.lower().strip()
    if any(c in q for c in 'æøå'):
        return 'da'
    for signal in DANISH_SIGNALS:
        if f' {signal} ' in f' {q} ' or q == signal:
            return 'da'
    return 'en'


def casual_type(question: str) -> str:
    q = question.lower().strip()
    if any(p in q for p in ['how are you', "how're you", 'hvordan går det', 'hvordan har du det']):
        return 'wellbeing'
    if q in ('tak', 'thanks', 'thank you') or q.startswith('tak'):
        return 'thanks'
    if any(p in q for p in ['bye', 'goodbye', 'farvel', 'ha det']):
        return 'goodbye'
    if any(p in q for p in ['godmorgen', 'good morning']):
        return 'morning'
    return 'greeting'


def test_hej_is_danish():
    assert detect_lang('hej') == 'da'
    assert casual_type('hej') == 'greeting'
    print('hej danish: OK')


def test_how_are_you_type():
    assert casual_type('how are you') == 'wellbeing'
    assert casual_type('hvordan går det?') == 'wellbeing'
    print('how are you type: OK')


def test_english_stays_english():
    assert detect_lang('how are you') == 'en'
    print('english stays english: OK')


if __name__ == '__main__':
    test_hej_is_danish()
    test_how_are_you_type()
    test_english_stays_english()
    print('All casual reply tests passed.')
