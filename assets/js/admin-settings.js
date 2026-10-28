(function ($) {
	'use strict';

	function initTabs() {
		var $tabs = $('.aiab-nav-tabs .nav-tab');
		var $panels = $('.aiab-tab-panel');

		function activateTab(tabId) {
			$tabs.removeClass('nav-tab-active');
			$panels.removeClass('is-active');
			$tabs.filter('[data-tab="' + tabId + '"]').addClass('nav-tab-active');
			$('#' + tabId).addClass('is-active');
			if (window.history && window.history.replaceState) {
				var url = new URL(window.location.href);
				url.searchParams.set('tab', tabId.replace('aiab-tab-', ''));
				window.history.replaceState({}, '', url.toString());
			}
		}

		$tabs.on('click', function (e) {
			e.preventDefault();
			activateTab($(this).data('tab'));
		});

		var params = new URLSearchParams(window.location.search);
		var initial = params.get('tab');
		if (initial) {
			var target = 'aiab-tab-' + initial;
			if ($('#' + target).length) {
				activateTab(target);
			}
		}
	}

	function initColorPicker() {
		if ($.fn.wpColorPicker) {
			$('.aiab-color-field').wpColorPicker();
		}
	}

	function initLogoPicker() {
		var frame;
		var $input = $('#logo_url');
		var $preview = $('#aiab-logo-preview');

		function renderPreview(url) {
			if (url) {
				$preview.removeClass('is-empty').empty().append($('<img>').attr('src', url).attr('alt', ''));
			} else {
				$preview.addClass('is-empty').text('Intet logo');
			}
		}

		renderPreview($input.val());

		$('#aiab-logo-select').on('click', function (e) {
			e.preventDefault();
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({
				title: 'Vælg chatbot-logo',
				button: { text: 'Brug dette billede' },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				$input.val(attachment.url);
				renderPreview(attachment.url);
			});
			frame.open();
		});

		$('#aiab-logo-remove').on('click', function (e) {
			e.preventDefault();
			$input.val('');
			renderPreview('');
		});
	}

	function initProviderDefaults() {
		var defaults = {
			gemini: 'gemini-2.0-flash',
			openai: 'gpt-4o-mini'
		};
		var allDefaults = Object.keys(defaults).map(function (key) {
			return defaults[key];
		});
		var $provider = $('#provider');
		var $model = $('#model');
		var $hints = $('#aiab-model-hints');

		function modelLooksLikeProvider(model, provider) {
			model = (model || '').toLowerCase();
			if (provider === 'gemini') {
				return model.indexOf('gemini') === 0;
			}
			if (provider === 'openai') {
				return model.indexOf('gpt-') === 0 || model.indexOf('o1') === 0 || model.indexOf('o3') === 0;
			}
			return false;
		}

		function updateHints() {
			var provider = $provider.val();
			$hints.find('code').css('font-weight', '');
			if (provider === 'gemini') {
				$hints.find('code').filter(function () {
					return $(this).text().indexOf('gemini') === 0;
				}).css('font-weight', '700');
			} else if (provider === 'openai') {
				$hints.find('code').filter(function () {
					return $(this).text().indexOf('gpt-') === 0;
				}).css('font-weight', '700');
			}
		}

		$provider.on('change', function () {
			var provider = $(this).val();
			var current = ($model.val() || '').trim();
			if (!current || allDefaults.indexOf(current) !== -1 || !modelLooksLikeProvider(current, provider)) {
				$model.val(defaults[provider] || '');
			}
			updateHints();
		});

		updateHints();
	}

	function initApiTest() {
		$('#aiab-test-key-btn').on('click', function () {
			var btn = $(this);
			var result = $('#aiab-test-key-result');
			btn.prop('disabled', true).text('Tester...');
			result.text('').removeClass('is-success is-error');
			$.post(AIAB_ADMIN.ajaxUrl, {
				action: 'aiab_test_key',
				nonce: AIAB_ADMIN.testNonce
			}).done(function (res) {
				btn.prop('disabled', false).text('Test API-nøgle');
				if (res.success) {
					result.addClass('is-success').css('color', '#1a7f37').text(res.data.message);
				} else {
					result.addClass('is-error').css('color', '#c0392b').text(res.data.message);
				}
			}).fail(function () {
				btn.prop('disabled', false).text('Test API-nøgle');
				result.addClass('is-error').css('color', '#c0392b').text('Anmodningen mislykkedes — tjek din forbindelse.');
			});
		});
	}

	function renderDocs(docs) {
		var $table = $('#aiab-doc-table');
		var $list = $('#aiab-doc-list');
		$list.empty();
		if (!docs || !docs.length) {
			$table.hide();
			return;
		}
		$table.show();
		docs.forEach(function (doc) {
			var row = $('<tr>').attr('data-doc-id', doc.id);
			row.append($('<td>').text(doc.name));
			row.append($('<td>').text(doc.size ? (Math.round(doc.size / 1024) + ' KB') : ''));
			row.append($('<td>').text((doc.char_count || 0).toLocaleString() + ' tegn (' + (doc.chunk_count || 0) + ' afsnit)'));
			row.append($('<td>').text(doc.uploaded || ''));
			var actions = $('<td class="aiab-link-actions">');
			actions.append(
				$('<button type="button" class="button aiab-doc-delete">').attr('data-id', doc.id).text('Slet')
			);
			row.append(actions);
			$list.append(row);
		});
	}

	function initDocumentationUpload() {
		var nonce = $('#aiab-doc-nonce').val();
		var status = $('#aiab-doc-upload-status');

		$('#aiab-doc-upload-btn').on('click', function () {
			var input = $('#aiab-doc-file')[0];
			if (!input || !input.files || !input.files.length) {
				status.css('color', '#c0392b').text('Vælg en fil først.');
				return;
			}
			var formData = new FormData();
			formData.append('action', 'aiab_upload_documentation');
			formData.append('nonce', nonce);
			formData.append('file', input.files[0]);
			var btn = $(this);
			var isPdf = input.files[0].name.toLowerCase().slice(-4) === '.pdf';
			btn.prop('disabled', true);
			status.css('color', '').text(isPdf ? 'Udtrækker tekst fra PDF via AI — vent venligst (kan tage op til 2 min)...' : 'Uploader...');
			$.ajax({
				url: AIAB_ADMIN.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				timeout: 180000
			}).done(function (res) {
				btn.prop('disabled', false);
				if (res.success) {
					status.css('color', '#1a7f37').text(res.data.message);
					renderDocs(res.data.docs);
					input.value = '';
				} else {
					status.css('color', '#c0392b').text(res.data && res.data.message ? res.data.message : 'Upload mislykkedes.');
				}
			}).fail(function (xhr, textStatus) {
				btn.prop('disabled', false);
				if (textStatus === 'timeout') {
					status.css('color', '#c0392b').text('Upload tog for lang tid. PDF-udtrækning kan tage op til 2 minutter — prøv igen, eller upload en .txt/.docx-fil.');
				} else {
					status.css('color', '#c0392b').text('Upload mislykkedes — tjek din forbindelse.');
				}
			});
		});

		$(document).on('click', '.aiab-doc-delete', function () {
			if (!confirm('Slet denne dokumentationsfil?')) {
				return;
			}
			var id = $(this).data('id');
			status.css('color', '').text('Sletter...');
			$.post(AIAB_ADMIN.ajaxUrl, {
				action: 'aiab_delete_documentation',
				nonce: nonce,
				id: id
			}).done(function (res) {
				if (res.success) {
					status.css('color', '#1a7f37').text(res.data.message);
					renderDocs(res.data.docs);
				} else {
					status.css('color', '#c0392b').text(res.data && res.data.message ? res.data.message : 'Sletning mislykkedes.');
				}
			}).fail(function () {
				status.css('color', '#c0392b').text('Sletning mislykkedes.');
			});
		});
	}

	function renderLinks(links) {
		var $table = $('#aiab-link-table');
		var $list = $('#aiab-link-list');
		$list.empty();
		if (!links || !links.length) {
			$table.hide();
			return;
		}
		$table.show();
		links.forEach(function (link) {
			var row = $('<tr>').attr('data-link-id', link.id);
			var titleCell = $('<td>');
			titleCell.append($('<strong>').text(link.title || ''));
			titleCell.append($('<br>'));
			titleCell.append(
				$('<a>').attr({ href: link.url, target: '_blank', rel: 'noopener noreferrer' }).text(link.url || '')
			);
			row.append(titleCell);
			row.append($('<td>').text(link.status_label || 'Gemt'));
			row.append($('<td>').text(link.fetched || ''));
			var actions = $('<td class="aiab-link-actions">');
			actions.append(
				$('<button type="button" class="button aiab-link-delete">').attr('data-id', link.id).text('Slet')
			);
			row.append(actions);
			$list.append(row);
		});
	}

	function initExternalLinks() {
		var nonce = $('#aiab-link-nonce').val();
		var status = $('#aiab-link-add-status');

		$('#aiab-link-add-btn').on('click', function () {
			var url = ($('#aiab-link-url').val() || '').trim();
			var title = ($('#aiab-link-title').val() || '').trim();
			if (!url) {
				status.css('color', '#c0392b').text('Indtast en URL først.');
				return;
			}
			var btn = $(this);
			btn.prop('disabled', true);
			status.css('color', '').text('Gemmer link...');
			$.post(AIAB_ADMIN.ajaxUrl, {
				action: 'aiab_add_external_link',
				nonce: nonce,
				url: url,
				title: title
			}).done(function (res) {
				btn.prop('disabled', false);
				if (res.success) {
					status.css('color', '#1a7f37').text(res.data.message);
					renderLinks(res.data.links);
					$('#aiab-link-url').val('');
					$('#aiab-link-title').val('');
				} else {
					status.css('color', '#c0392b').text(res.data && res.data.message ? res.data.message : 'Kunne ikke tilføje linket.');
				}
			}).fail(function () {
				btn.prop('disabled', false);
				status.css('color', '#c0392b').text('Anmodningen mislykkedes — tjek din forbindelse.');
			});
		});

		$(document).on('click', '.aiab-link-delete', function () {
			if (!confirm('Slet dette eksterne link?')) {
				return;
			}
			var id = $(this).data('id');
			status.css('color', '').text('Sletter...');
			$.post(AIAB_ADMIN.ajaxUrl, {
				action: 'aiab_delete_external_link',
				nonce: nonce,
				id: id
			}).done(function (res) {
				if (res.success) {
					status.css('color', '#1a7f37').text(res.data.message);
					renderLinks(res.data.links);
				} else {
					status.css('color', '#c0392b').text(res.data && res.data.message ? res.data.message : 'Sletning mislykkedes.');
				}
			}).fail(function () {
				status.css('color', '#c0392b').text('Sletning mislykkedes.');
			});
		});
	}

	$(function () {
		if (!$('.aiab-admin-wrap').length) {
			return;
		}
		initTabs();
		initColorPicker();
		initLogoPicker();
		initProviderDefaults();
		initApiTest();
		initDocumentationUpload();
		initExternalLinks();
	});
})(jQuery);
