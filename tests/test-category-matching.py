#!/usr/bin/env python3
"""Sanity checks for when category/link cards should appear."""

GENERAL_PATTERNS = [
    'how many', 'how much', 'number of', 'count of', 'total number', 'total products',
    'products available', 'products do you have', 'product are available', 'items available',
    'what do you sell', 'what do you offer', 'what products', 'what machines do you',
    'opening hour', 'opening time', 'open at', 'when are you open', 'store hours',
    'where are you', 'your address', 'contact you', 'phone number', 'email address',
    'about your store', 'about your shop', 'in this store', 'in the store',
    'hvor mange', 'antal produkter', 'antal maskiner', 'produkter har i', 'produkter har du',
    'maskiner har i', 'hvad sælger', 'hvad tilbyder', 'åbningstid', 'åbent', 'hvornår har i åbent',
    'hvor ligger', 'adresse', 'kontakt os', 'telefonnummer', 'e-mail adresse',
]

INFORMATIONAL_PATTERNS = [
    'hvad koster', 'hvad koster det', 'hvad koster en', 'hvad koster service',
    'what does it cost', 'what is the price', 'how much does', 'how much is',
    'pris på', 'pris for', 'koster det', 'koster service', 'koster ',
    'service af', 'service på', 'service for', 'service of',
    'how does', 'how do i', 'how can i', 'how to', 'hvordan',
    'what is', 'what are', 'hvad er', 'hvad er der',
    'warranty', 'garanti', 'vedligehold', 'maintenance', 'repair', 'reparation',
]

LINK_CARD_PATTERNS = [
    'show me', 'show us', 'vis mig', 'vis os', 'see your', 'se jeres',
    'browse', 'udvalg', 'sortiment', 'types of', 'slags', 'slag af',
    'which models', 'hvilke modeller', 'what models',
    'tilbud', 'kampagnetilbud', 'kampagne', 'offer', 'offers',
    'recommend', 'anbefal', 'fortæl om', 'tell me about the',
]

DEALER_SIGNALS = [
    'forhandler', 'dealer', 'sælger', 'sell', 'har i', 'do you have', 'do you sell',
]


def is_general_catalog_question(question: str) -> bool:
    q = question.lower().strip()
    return any(p in q for p in GENERAL_PATTERNS)


def is_informational_question(question: str) -> bool:
    q = question.lower().strip()
    return any(p in q for p in INFORMATIONAL_PATTERNS)


def is_simple_dealer_question(question: str) -> bool:
    q = question.lower().strip()
    if not any(s in q for s in DEALER_SIGNALS):
        return False
    words = q.split()
    return len(words) <= 8 and q.endswith('?')


def question_wants_link_cards(question: str) -> bool:
    if is_simple_dealer_question(question):
        return False
    q = question.lower().strip()
    if any(p in q for p in ['tilbud', 'kampagnetilbud', 'kampagne', 'offer', 'offers', 'vis mig', 'show me']):
        return True
    if is_informational_question(question):
        return False
    return any(p in q for p in LINK_CARD_PATTERNS)


def question_wants_category_browse(question: str) -> bool:
    return question_wants_link_cards(question)


def test_general_questions():
    assert is_general_catalog_question('how many product are available in this store')
    assert is_general_catalog_question('what is opening time for this store')
    assert is_general_catalog_question('hvor mange produkter har i')
    assert not is_general_catalog_question('do you have new holland traktorer')
    print('general catalog detection: OK')


def test_informational_no_cards():
    assert is_informational_question('hvad koster service af robotplæneklipper?')
    assert is_informational_question('what is the price of a robot mower?')
    assert is_informational_question('hvordan vedligeholder jeg min robotplæneklipper?')
    assert not question_wants_link_cards('hvad koster service af robotplæneklipper?')
    assert not question_wants_category_browse('hvad koster service af robotplæneklipper?')
    print('informational no cards: OK')


def test_dealer_yes_no_no_cards():
    assert is_simple_dealer_question('forhandler i pronar?')
    assert is_simple_dealer_question('do you sell husqvarna?')
    assert not question_wants_link_cards('forhandler i pronar?')
    print('dealer yes/no no cards: OK')


def test_browse_and_offers_show_cards():
    assert question_wants_link_cards('vis mig husqvarna robotplæneklippere')
    assert question_wants_link_cards('hvad er jeres husqvarna tilbud?')
    assert question_wants_category_browse('vis mig robotplæneklippere')
    assert question_wants_link_cards('husqvarna kampagnetilbud')
    print('browse/offer cards: OK')


if __name__ == '__main__':
    test_general_questions()
    test_informational_no_cards()
    test_dealer_yes_no_no_cards()
    test_browse_and_offers_show_cards()
    print('All tests passed.')
