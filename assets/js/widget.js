(function () {
	'use strict';
	var MAX_HISTORY_MESSAGES = 20; // 10 Q&A turns kept as context for the AI.
	var DEFAULT_LANG = 'en';
	/**
	 * Bilingual chrome strings for a given turn. `lang` should be "en" or
	 * "da" — normally data.lang from the server (the language the AI
	 * detected for that specific question and answered in). Falls back to
	 * AIAB_DATA's old single-language fields if the strings table isn't
	 * present, and to English if a requested language/key is missing.
	 */
	function t(lang, key) {
		var table = (AIAB_DATA && AIAB_DATA.strings) || {};
		var set = table[lang] || table[DEFAULT_LANG];
		if (set && set[key]) return set[key];
		var fallbackSet = table[DEFAULT_LANG];
		if (fallbackSet && fallbackSet[key]) return fallbackSet[key];
		return '';
	}
	/**
	 * Cheap client-side guess at the visitor's language, used only for the
	 * instant "Thinking..." state before the server (and the AI's own
	 * language detection) responds. The authoritative choice for the
	 * rendered answer always comes from data.lang once the reply arrives.
	 */
	function guessLang(text) {
		if (/[æøåÆØÅ]/.test(text)) return 'da';
		if (/\b(og|jeg|ikke|hvad|med|for|som|til|kan|har|vores|pris|søg)\b/i.test(text)) return 'da';
		return DEFAULT_LANG;
	}
	function initWidget(root) {
		var shell = root.querySelector('.aiab-shell');
		var closeBtn = root.querySelector('.aiab-close');
		var form = root.querySelector('.aiab-form');
		var input = root.querySelector('.aiab-input');
		var micBtn = root.querySelector('.aiab-mic');
		var newChatBtn = root.querySelector('.aiab-newchat-btn');
		var headerEl = root.querySelector('.aiab-header');
		var searchView = root.querySelector('.aiab-view-search');
		var conversationView = root.querySelector('.aiab-view-conversation');
		var conversationScroll = root.querySelector('.aiab-conversation-scroll');
		var history = []; // [{role:'user'|'assistant', content:string}], sent to the server for context.
		var busy = false;
		if (closeBtn) {
			closeBtn.addEventListener('click', function (e) {
				if (window.history.length > 1 && document.referrer) {
					e.preventDefault();
					window.history.back();
				}
				// otherwise fall through to the href (site homepage).
			});
		}
		if (newChatBtn) {
			newChatBtn.addEventListener('click', function () {
				startNewChat();
			});
		}
		// Voice input via Web Speech API, if supported.
		var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
		if (SpeechRecognition && micBtn) {
			var recognition = new SpeechRecognition();
			recognition.continuous = false;
			recognition.interimResults = false;
			micBtn.addEventListener('click', function () {
				micBtn.classList.add('aiab-listening');
				recognition.start();
			});
			recognition.onresult = function (e) {
				input.value = e.results[0][0].transcript;
				micBtn.classList.remove('aiab-listening');
				submitQuestion(input.value.trim());
			};
			recognition.onerror = function () { micBtn.classList.remove('aiab-listening'); };
			recognition.onend = function () { micBtn.classList.remove('aiab-listening'); };
		} else if (micBtn) {
			micBtn.style.display = 'none';
		}
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var q = input.value.trim();
			if (!q || busy) return;
			input.value = '';
			submitQuestion(q);
		});
		function startNewChat() {
			history = [];
			conversationScroll.innerHTML = '';
			conversationView.hidden = true;
			searchView.hidden = false;
			if (headerEl) headerEl.hidden = true;
			input.value = '';
			input.focus();
		}
		function showConversationView() {
			if (searchView.hidden) return;
			searchView.hidden = true;
			conversationView.hidden = false;
			if (headerEl) headerEl.hidden = false;
		}
		function scrollToBottom() {
			conversationScroll.scrollTop = conversationScroll.scrollHeight;
		}
		function submitQuestion(question) {
			if (!question) return;
			busy = true;
			var guessedLang = guessLang(question);
			showConversationView();
			// Add user's question
			var userEl = appendUserTurn(question);
			// Add loading answer
			var loadingEl = appendLoadingTurn(guessedLang);
			// Move NEW question to top
			requestAnimationFrame(function () {
				scrollQuestionToTop(userEl);
			});
			fetch(AIAB_DATA.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					question: question,
					history: history
				})
			})
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				busy = false;
				if (!json.success) {
					loadingEl.innerHTML =
						'<div class="aiab-error">' +
						escapeHtml(
							json.message ||
							t(guessedLang, 'error')
						) +
						'</div>';
					return;
				}
				// Render answer
				renderAssistantTurn(
					loadingEl,
					json.data,
					guessedLang
				);
				// Keep current question at top
				requestAnimationFrame(function () {
					scrollQuestionToTop(userEl);
				});
				// Save conversation history
				history.push({
					role: 'user',
					content: question
				});
				history.push({
					role: 'assistant',
					content: JSON.stringify(json.data)
				});
				if (history.length > MAX_HISTORY_MESSAGES) {
					history = history.slice(
						history.length - MAX_HISTORY_MESSAGES
					);
				}
			})
			.catch(function () {
				busy = false;
				loadingEl.innerHTML =
					'<div class="aiab-error">' +
					escapeHtml(
						t(guessedLang, 'error')
					) +
					'</div>';
			});
		}
		function scrollQuestionToTop(userEl) {
			if (!userEl || !conversationScroll) return;

			var topOffset = 30;

			var targetTop =
				userEl.offsetTop - topOffset;

			conversationScroll.scrollTo({
				top: targetTop,
				behavior: 'smooth'
			});
		}
		function appendUserTurn(question) {
			var el = document.createElement('div');
			el.className = 'aiab-turn aiab-turn-user';

			var bubble = document.createElement('div');
			bubble.className = 'aiab-bubble-user';
			bubble.textContent = question;

			el.appendChild(bubble);
			conversationScroll.appendChild(el);

			return el;
		}
		function appendLoadingTurn(lang) {
			var el = document.createElement('div');
			el.className = 'aiab-turn aiab-turn-assistant';
			el.innerHTML = '<div class="aiab-loading">' + escapeHtml(t(lang, 'thinking')) + '</div>';
			conversationScroll.appendChild(el);
			// scrollToBottom();
			return el;
		}
		function renderAssistantTurn(container, data, guessedLang) {
			container.innerHTML = '';
			// The AI reports which language ("en"/"da") it answered THIS
			// question in — use that for the answer card's chrome (section
			// headings, buttons) so it matches. Falls back to the pre-request
			// guess if the server didn't provide one.
			var lang = (data && (data.lang === 'en' || data.lang === 'da')) ? data.lang : guessedLang;
			// Safety net: data should always be a plain object shaped like
			// {title, chip, answer, products, ...}. If it's anything else
			// (a string, null, an array), something upstream went wrong —
			// never let that raw payload leak into the chat as text.
			if (!data || typeof data !== 'object' || Array.isArray(data)) {
				container.innerHTML = '<div class="aiab-error">' + escapeHtml(t(lang, 'genericError')) + '</div>';
				scrollToBottom();
				return;
			}
			if (!data.answer && !(data.products && data.products.length)) {
				container.innerHTML = '<div class="aiab-error">' + escapeHtml(t(lang, 'noMatch')) + '</div>';
				scrollToBottom();
				return;
			}
			var card = document.createElement('div');
			card.className = 'aiab-answer-card';
			// The small blue topic-label badge (e.g. "Agenturer",
			// "Robotplæneklipper") is intentionally not rendered anymore —
			// removed per request. data.chip is still returned by the
			// backend/AI but simply ignored here.
			if (data.title) {
				var title = document.createElement('h3');
				title.className = 'aiab-result-title';
				title.textContent = data.title;
				card.appendChild(title);
			}
			if (data.answer) {
				var answer = document.createElement('div');
				answer.className = 'aiab-answer';
				answer.innerHTML = renderMarkdown(data.answer);
				card.appendChild(answer);
			}
			// Clarification questions section: if the AI needs clarification, show it prominently
			if (data.needs_clarification && data.clarification_questions && data.clarification_questions.length) {
				var clarificationSection = document.createElement('div');
				clarificationSection.className = 'aiab-section aiab-section-clarification';
				clarificationSection.innerHTML = '<div class="aiab-section-title">' + (lang === 'da' ? 'Hjælp mig med at forstå:' : 'Help me understand:') + '</div>';
				var clarificationList = document.createElement('div');
				clarificationList.className = 'aiab-clarification-questions';
				data.clarification_questions.forEach(function (q) {
					var btn = document.createElement('button');
					btn.className = 'aiab-clarification-btn';
					btn.textContent = q;
					btn.onclick = function () {
						submitQuestion(q);
					};
					clarificationList.appendChild(btn);
				});
				clarificationSection.appendChild(clarificationList);
				card.appendChild(clarificationSection);
			}
			if (data.category && data.category.title) {
				card.appendChild(buildCategorySection(data.category, lang));
			}
			if (data.contact && data.contact.contacts && data.contact.contacts.length) {
				card.appendChild(buildContactSection(data.contact, lang));
			}
			if (data.products && data.products.length) {
				var productsSection = document.createElement('div');
				productsSection.className = 'aiab-section aiab-section-products';
				productsSection.innerHTML = '<div class="aiab-section-title"><span class="aiab-ico">&#9733;</span> ' + escapeHtml(t(lang, 'products')) + '</div>';
				var cardsEl = document.createElement('div');
				cardsEl.className = 'aiab-cards';
				data.products.forEach(function (p) {
					var a = document.createElement('a');
					a.className = 'aiab-card';
					a.href = p.permalink || '#';
					if (p.permalink && /^https?:\/\//i.test(p.permalink) && p.permalink.indexOf(window.location.host) === -1) {
						a.target = '_blank';
						a.rel = 'noopener noreferrer';
					}
					var imgHtml = p.image ? '<img src="' + escapeAttr(p.image) + '" alt="' + escapeAttr(p.title || '') + '">' : '';
					var descHtml = p.description ? '<div class="aiab-card-desc">' + escapeHtml(p.description) + '</div>' : '';
					a.innerHTML = imgHtml +
						'<div class="aiab-card-body">' +
						'<div class="aiab-card-title">' + escapeHtml(p.title || '') + '</div>' +
						descHtml +
						'<div class="aiab-card-link">' + escapeHtml(t(lang, 'seeDetails')) + ' &rarr;</div>' +
						'</div>';
					cardsEl.appendChild(a);
				});
				productsSection.appendChild(cardsEl);
				card.appendChild(productsSection);
			}
			// Follow-up suggestions section: show related questions the visitor might want to ask
			if (data.follow_up_suggestions && data.follow_up_suggestions.length) {
				var followUpSection = document.createElement('div');
				followUpSection.className = 'aiab-section aiab-section-followup';
				followUpSection.innerHTML = '<div class="aiab-section-title">' + (lang === 'da' ? 'Du kan også spørge om:' : 'You can also ask about:') + '</div>';
				var followUpList = document.createElement('div');
				followUpList.className = 'aiab-follow-up-questions';
				data.follow_up_suggestions.forEach(function (q) {
					var btn = document.createElement('button');
					btn.className = 'aiab-follow-up-btn';
					btn.textContent = q;
					btn.onclick = function () {
						submitQuestion(q);
					};
					followUpList.appendChild(btn);
				});
				followUpSection.appendChild(followUpList);
				card.appendChild(followUpSection);
			}
			container.appendChild(card);
		}
		/**
		 * Deterministic "matched category" block: shown only when the
		 * question resolved to a real taxonomy term (data.category is set
		 * by PHP, never by the AI). Shows the first 4 subcategories as
		 * cards, always followed by a "see more" link to the parent
		 * category page so the visitor can browse the full category on the
		 * site; falls back to a single card linking straight to the parent
		 * category when it has no subcategories at all.
		 */
		function buildCategorySection(category, lang) {
			var section = document.createElement('div');
			section.className = 'aiab-section aiab-section-category';
			var subcats = (category.subcategories && category.subcategories.length) ? category.subcategories : [];
			var hasChildren = subcats.length > 0;
			var cardsEl = document.createElement('div');
			cardsEl.className = 'aiab-cat-cards';
			var itemsToShow = hasChildren ? subcats : [{
				title: category.title,
				permalink: category.permalink,
				image: category.image
			}];
			itemsToShow.forEach(function (c) {
				var a = document.createElement('a');
				a.className = 'aiab-cat-card';
				a.href = c.permalink || '#';
				var imgHtml = c.image
					? '<img src="' + escapeAttr(c.image) + '" alt="' + escapeAttr(c.title || '') + '">'
					: '<div class="aiab-cat-card-noimg"></div>';
				a.innerHTML = imgHtml + '<div class="aiab-cat-card-title">' + escapeHtml(c.title || '') + '</div>';
				cardsEl.appendChild(a);
			});
			section.appendChild(cardsEl);
			// Always link to the parent category page when subcategories were
			// shown, so the visitor can browse the full category on the site
			// — not just gated behind "more than 4 children exist".
			if (hasChildren) {
				var moreBtn = document.createElement('a');
				moreBtn.className = 'aiab-cat-more-btn';
				moreBtn.href = category.more_link || category.permalink || '#';
				var label = t(lang, 'seeMore');
				if (category.has_more && category.more_count) {
					label += ' (+' + category.more_count + ')';
				}
				moreBtn.textContent = label;
				section.appendChild(moreBtn);
			}
			return section;
		}
		/**
		 * Deterministic "contact person" block: shown only when the
		 * question's intent resolved to a real contact person (data.contact
		 * is set by PHP directly from ACF data, never invented by the AI).
		 * Renders each contact's name with tappable call/email links and
		 * the category/department they belong to.
		 */
		function buildContactSection(contact, lang) {
			var section = document.createElement('div');
			section.className = 'aiab-section aiab-section-contact';
			var heading = document.createElement('div');
			heading.className = 'aiab-section-title';
			heading.innerHTML = '<span class="aiab-ico">&#9742;</span> ' + escapeHtml(t(lang, 'contact') || 'Contact')
				+ (contact.category ? ' \u2013 ' + escapeHtml(contact.category) : '');
			section.appendChild(heading);
			var list = document.createElement('div');
			list.className = 'aiab-contact-cards';
			contact.contacts.forEach(function (c) {
				var item = document.createElement('div');
				item.className = 'aiab-contact-card';
				var nameHtml = c.navn ? '<div class="aiab-contact-name">' + escapeHtml(c.navn) + '</div>' : '';
				var linksHtml = '';
				if (c.telefon) {
					linksHtml += '<a class="aiab-contact-link" href="tel:' + escapeAttr(c.telefon.replace(/\s+/g, '')) + '">'
						+ escapeHtml(t(lang, 'call') || 'Call') + ': ' + escapeHtml(c.telefon) + '</a>';
				}
				if (c.email) {
					linksHtml += '<a class="aiab-contact-link" href="mailto:' + escapeAttr(c.email) + '">'
						+ escapeHtml(t(lang, 'email') || 'Email') + ': ' + escapeHtml(c.email) + '</a>';
				}
				item.innerHTML = nameHtml + linksHtml;
				list.appendChild(item);
			});
			section.appendChild(list);
			return section;
		}

		/**
		 * Coerce a features/support entry to a plain string. The AI is
		 * asked for plain strings, but occasionally returns an object
		 * instead (e.g. {label, value}) — without this, that would render
		 * as the literal text "[object Object]".
		 */
		function stringifyListItem(item) {
			if (typeof item === 'string') return item;
			if (item === null || item === undefined) return '';
			if (typeof item === 'number' || typeof item === 'boolean') return String(item);
			if (typeof item === 'object') {
				if (typeof item.text === 'string') return item.text;
				if (typeof item.value === 'string') {
					return item.label ? (item.label + ': ' + item.value) : item.value;
				}
				var parts = Object.keys(item)
					.map(function (k) { return item[k]; })
					.filter(function (v) { return typeof v === 'string' && v !== ''; });
				if (parts.length) return parts.join(' ');
			}
			return '';
		}
		function escapeHtml(str) {
			var div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		}
		function escapeAttr(str) {
			return String(str).replace(/"/g, '&quot;');
		}

		/**
		 * Lightweight Markdown → HTML for AI answers (headings, bold, lists, links).
		 */
		function renderMarkdown(text) {
			if (!text) return '';
			var lines = String(text).split('\n');
			var html = [];
			var inUl = false;
			var inOl = false;

			function closeLists() {
				if (inUl) { html.push('</ul>'); inUl = false; }
				if (inOl) { html.push('</ol>'); inOl = false; }
			}

			function inlineFormat(s) {
				s = escapeHtml(s);
				s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
				s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (_, label, url) {
					return '<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
				});
				return s;
			}

			lines.forEach(function (line) {
				var trimmed = line.trim();
				if (/^[-*] /.test(trimmed)) {
					if (!inUl) { closeLists(); html.push('<ul class="aiab-md-ul">'); inUl = true; }
					html.push('<li>' + inlineFormat(trimmed.replace(/^[-*] /, '')) + '</li>');
					return;
				}
				if (/^\d+\.\s/.test(trimmed)) {
					if (!inOl) { closeLists(); html.push('<ol class="aiab-md-ol">'); inOl = true; }
					html.push('<li>' + inlineFormat(trimmed.replace(/^\d+\.\s/, '')) + '</li>');
					return;
				}
				closeLists();
				if (/^### /.test(trimmed)) {
					html.push('<h5 class="aiab-md-h">' + inlineFormat(trimmed.slice(4)) + '</h5>');
				} else if (/^## /.test(trimmed)) {
					html.push('<h4 class="aiab-md-h">' + inlineFormat(trimmed.slice(3)) + '</h4>');
				} else if (trimmed) {
					html.push('<p class="aiab-md-p">' + inlineFormat(trimmed) + '</p>');
				}
			});
			closeLists();
			return html.join('');
		}
	}
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.aiab-root').forEach(initWidget);
	});
})();
function initConversationScroll() {
    const el = document.querySelector(".aiab-conversation-scroll");
    if (!el) return;
    const check = () => {
        const isScrollable = el.scrollHeight > el.clientHeight;
        el.classList.toggle("aiab-has-scroll", isScrollable);
        // Don't reset scroll position here
        // el.scrollTop = 0;
    };
    check();
    new MutationObserver(check).observe(el, {
        childList: true,
        subtree: true
    });
    window.addEventListener("resize", check);
}
document.addEventListener("DOMContentLoaded", initConversationScroll);
