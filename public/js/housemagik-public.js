/**
 * Housemagik Public JavaScript
 */

(function($) {
	'use strict';

	let sessionId = null;
	let currentResults = [];
	let emptySuggestionApply = null;
	let emptySuggestionResults = null;

	$(document).ready(function() {
		initializeApp();
	});

	const SEARCH_STORE = 'housemajik_last_search';

	function strokeIcon(path) {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + path + '" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="miter"/></svg>';
	}

	function initializeApp() {
		expireCookie('housemajik_note_prompted');

		if (/[?&]housemajik_reset=1(?:&|$)/.test(window.location.search)) {
			expireCookie('housemajik_lead');
			expireCookie('housemajik_registered');
			expireCookie('housemajik_buyer_name');
		}

		$('#housemajik-search').on('submit', handleSearch);
		$('#housemajik-register-form').on('submit', handleRegister);
		$('#housemajik-modal-close, #housemajik-skip-register').on('click', closeModal);
		$('#housemajik-empty-edit').on('click', function() {
			const form = document.getElementById('housemajik-form');
			if (form) {
				form.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
		$('#housemajik-empty-suggest').on('click', applyEmptySuggestion);
		$('.housemajik-modal-overlay').on('click', closeModal);
		$(document).on('click', '.housemajik-reaction-save', handleSaveReaction);
		$(document).on('click', '.housemajik-save-property', handleSaveProperty);
		$(document).on('click', '.housemajik-stepper-btn', handleStepper);
		$(document).on('click', '.housemajik-btn-link', saveSearchForm);
		$('#housemajik-search').on('change input', saveSearchForm);
		bindPriceField();
		bindLocationField();
		$('#land_only').on('change', applyLandOnlyMode);

		restoreSearchForm();
		applyLandOnlyMode();

		if (typeof housemajik !== 'undefined' && housemajik.is_detail && housemajik.listing_id) {
			ensureDetailNotes(housemajik.listing_id);
			ensureBackToListings();
			$('#reg_listing_id').val(housemajik.listing_id);
		}
	}

	function collectSearchForm() {
		const $form = $('#housemajik-search');
		if (!$form.length) {
			return null;
		}

		return {
			must_have: $form.find('[name="must_have"]').val() || '',
			would_like: $form.find('[name="would_like"]').val() || '',
			never: $form.find('[name="never"]').val() || '',
			location: selectedLocations($form).join(', ') || locationStatewide($form),
			beds: $form.find('[name="beds"]').val() || '',
			beds_mode: $form.find('[name="beds_mode"]').val() || 'at_least',
			baths: $form.find('[name="baths"]').val() || '',
			baths_mode: $form.find('[name="baths_mode"]').val() || 'at_least',
			max_price: parsePrice($form.find('[name="max_price"]').val()) || '',
			garage: $form.find('[name="garage"]').val() || 'dont_care',
			acres: $form.find('[name="acres"]').val() || '',
			land_only: $form.find('[name="land_only"]').is(':checked')
		};
	}

	function saveSearchForm() {
		const data = collectSearchForm();
		if (!data) {
			return;
		}

		try {
			localStorage.setItem(SEARCH_STORE, JSON.stringify(data));
		} catch (err) {
			// Ignore quota / private-mode failures.
		}
	}

	function restoreSearchForm() {
		const $form = $('#housemajik-search');
		if (!$form.length) {
			return;
		}

		let data = null;
		try {
			data = JSON.parse(localStorage.getItem(SEARCH_STORE) || '');
		} catch (err) {
			return;
		}

		if (!data || typeof data !== 'object') {
			return;
		}

		const modes = { at_least: true, exactly: true };
		const garages = { dont_care: true, yes: true, no: true };

		if (data.must_have) {
			$form.find('[name="must_have"]').val(data.must_have);
		} else if (data.dream_home) {
			$form.find('[name="must_have"]').val(data.dream_home);
		}
		if (data.would_like) {
			$form.find('[name="would_like"]').val(data.would_like);
		}
		if (typeof data.never === 'string') {
			$form.find('[name="never"]').val(data.never);
		} else if (typeof data.dont_want === 'string') {
			$form.find('[name="never"]').val(data.dont_want);
		}
		if (data.location) {
			setLocations($form, data.location);
		}
		if (data.beds) {
			$form.find('[name="beds"]').val(data.beds);
		}
		if (data.beds_mode && modes[data.beds_mode]) {
			$form.find('[name="beds_mode"]').val(data.beds_mode);
		}
		if (data.baths) {
			$form.find('[name="baths"]').val(data.baths);
		}
		if (data.baths_mode && modes[data.baths_mode]) {
			$form.find('[name="baths_mode"]').val(data.baths_mode);
		}
		if (data.max_price) {
			$form.find('[name="max_price"]').val(formatPriceDisplay(data.max_price));
		}
		if (data.garage && garages[data.garage]) {
			$form.find('[name="garage"]').val(data.garage);
		}
		if (data.acres) {
			$form.find('[name="acres"]').val(data.acres);
		}
		$form.find('[name="land_only"]').prop('checked', !!data.land_only);
		applyLandOnlyMode();
	}

	function listingsBackUrl() {
		const base = (typeof housemajik !== 'undefined' && housemajik.search_url)
			? housemajik.search_url
			: window.location.origin + '/';
		return String(base).split('#')[0];
	}

	function ensureBackToListings() {
		const resultsUrl = listingsBackUrl();
		const $sidebar = $('.housemajik-detail-sidebar');
		if (!$sidebar.length) {
			return;
		}

		$sidebar.find('.housemajik-back-to-listings').attr('href', resultsUrl);

		const $searchBack = $sidebar.find('a').filter(function() {
			const label = $(this).text().replace(/\s+/g, ' ');
			return label.indexOf('Back to Search') !== -1 || label.indexOf('Back to chosen listings') !== -1;
		});

		$searchBack.attr('href', resultsUrl).text('← Back to Search');
		$searchBack.slice(1).remove();
	}

	function applyLandOnlyMode() {
		const $form = $('#housemajik-search');
		if (!$form.length) {
			return;
		}
		const land = $form.find('[name="land_only"]').is(':checked');
		$form.toggleClass('is-land-only', land);
		$form.find('#beds, #beds_mode, #baths, #baths_mode, #garage')
			.prop('disabled', land);
		$form.find('.housemajik-room-field .housemajik-stepper-btn').prop('disabled', land);
		$form.find('#housemajik-submit .housemajik-btn-text').text(
			land ? 'Show me land closest to this' : 'Show me houses closest to this'
		);
		const $must = $form.find('#must_have');
		if (!$must.data('housePlaceholder')) {
			$must.data('housePlaceholder', $must.attr('placeholder') || '');
		}
		$must.attr(
			'placeholder',
			land
				? 'Without this, skip the land. (e.g., at least two acres, power at the road, no HOA)'
				: $must.data('housePlaceholder')
		);
		const $title = $form.closest('.housemajik-container').find('.housemajik-header h2').first();
		if ($title.length) {
			if (!$title.data('houseTitle')) {
				$title.data('houseTitle', $title.text());
			}
			const houseTitle = $title.data('houseTitle') || '';
			if (land && /home|house/i.test(houseTitle)) {
				$title.text('Let us help you find land');
			} else {
				$title.text(houseTitle);
			}
		}
	}

	function isLandListing(listing) {
		return !!(listing && (listing.land || String(listing.property_type || '').toLowerCase() === 'land'));
	}

	function handleStepper(e) {
		e.preventDefault();
		if ($(this).prop('disabled')) {
			return;
		}

		const $input = $(this).closest('.housemajik-stepper').find('input[type="number"]');
		if (!$input.length) {
			return;
		}

		const step = parseFloat($input.attr('step') || '1');
		const minAttr = $input.attr('min');
		const maxAttr = $input.attr('max');
		const min = minAttr === undefined || minAttr === '' ? null : parseFloat(minAttr);
		const max = maxAttr === undefined || maxAttr === '' ? null : parseFloat(maxAttr);
		const decimals = (String(step).split('.')[1] || '').length;
		let value = parseFloat($input.val());

		if (isNaN(value)) {
			value = min !== null ? min : 0;
		}

		value = $(this).data('stepper') === 'up' ? value + step : value - step;
		value = parseFloat(value.toFixed(decimals));

		if (min !== null && value < min) {
			value = min;
		}
		if (max !== null && value > max) {
			value = max;
		}

		$input.val(decimals ? value.toFixed(decimals) : String(value));
		saveSearchForm();
	}

	function listingPhotos(listing) {
		if (!listing || !$.isArray(listing.photos)) {
			return [];
		}
		return listing.photos.filter(function(src) {
			return !!src;
		});
	}

	function decorativePhotoChrome() {
		return [
			$('<span>', {
				class: 'housemajik-photo-nav housemajik-photo-prev',
				'aria-hidden': 'true',
				html: strokeIcon('M15 5L8 12l7 7')
			}),
			$('<span>', {
				class: 'housemajik-photo-nav housemajik-photo-next',
				'aria-hidden': 'true',
				html: strokeIcon('M9 5l7 7-7 7')
			}),
			$('<span>', {
				class: 'housemajik-photo-count',
				text: '1 / 4'
			})
		];
	}

	function handleSearch(e) {
		e.preventDefault();

		const $form = $(this);
		const $submit = $('#housemajik-submit');
		const $btnText = $submit.find('.housemajik-btn-text');
		const $spinner = $submit.find('.housemajik-spinner');
		const searchUrl = window.location.href;

		if (!selectedLocations($form).length && locationStatewide($form) !== 'Arizona') {
			alert('Please choose at least one city.');
			$('#location-toggle').trigger('focus');
			return;
		}

		const must = $.trim($form.find('[name="must_have"]').val() || '');
		const want = $.trim($form.find('[name="would_like"]').val() || '');
		if (!must && !want) {
			alert('Please tell us what you must have or would like.');
			$('#must_have').trigger('focus');
			return;
		}

		setCookie('housemajik_search_url', searchUrl, 7);
		saveSearchForm();

		$submit.prop('disabled', true);
		$btnText.text('Searching...');
		$spinner.show();

		resetResultsChrome();
		$('#housemajik-results, #housemajik-empty').hide();

		const formData = new URLSearchParams($form.serialize());
		formData.set('max_price', parsePrice($form.find('[name="max_price"]').val()) || '');
		if (!selectedLocations($form).length && locationStatewide($form) === 'Arizona') {
			formData.set('location', 'Arizona');
		}

		$.ajax({
			url: housemajik.ajax_url,
			type: 'POST',
			data: formData.toString() + '&action=housemajik_search&nonce=' + housemajik.nonce + '&search_url=' + encodeURIComponent(searchUrl),
			success: function(response) {
				if (response.success) {
					sessionId = response.data.session_id;
					currentResults = response.data.results;

					if (response.data.tradeoff && response.data.tradeoff.piles) {
						displayTradeoff(response.data.tradeoff);
					} else if (response.data.empty) {
						showEmpty(response.data.message, response.data.suggestion || null);
					} else {
						displayResults(response.data.results);
					}

					setTimeout(function() {
						const target = response.data.empty && !response.data.tradeoff ? '#housemajik-empty' : '#housemajik-results';
						$(target)[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
					}, 100);
				} else {
					alert(response.data.message || 'Search failed. Please try again.');
				}
			},
			error: function(xhr) {
				console.error('Search error:', xhr);
				var message = 'An error occurred. Please try again.';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				} else if (xhr.status === 0) {
					message = 'The search timed out or could not reach the server. Please try again.';
				} else if (xhr.status) {
					message = 'Search failed (error ' + xhr.status + '). Please try again.';
				}
				alert(message);
			},
			complete: function() {
				$submit.prop('disabled', false);
				applyLandOnlyMode();
				$spinner.hide();
			}
		});
	}

	function resetResultsChrome() {
		$('#housemajik-results-title').text('Here are your top matches');
		$('#housemajik-tradeoff-copy').text('').hide();
		$('#housemajik-tradeoff').empty().hide();
		$('#housemajik-listings').empty().show();
		$('.housemajik-container').removeClass('housemajik-showing-tradeoff');
	}

	function displayResults(results) {
		resetResultsChrome();
		const $container = $('#housemajik-listings');

		results.forEach(function(listing) {
			$container.append(buildListingCard(listing));
		});

		$('#housemajik-results').show();
	}

	function displayTradeoff(tradeoff) {
		resetResultsChrome();
		$('#housemajik-results-title').text(tradeoff.headline || 'These rarely appear together');
		if (tradeoff.message) {
			$('#housemajik-tradeoff-copy').text(tradeoff.message).show();
		}

		const $root = $('#housemajik-tradeoff');
		(tradeoff.piles || []).forEach(function(pile) {
			const $col = $('<section>', { class: 'housemajik-tradeoff-pile' });
			$col.append($('<h4>', { text: pile.title || ($('#land_only').is(':checked') ? 'This land' : 'These homes') }));
			if (pile.keep || pile.give_up) {
				$col.append(tradeoffSwap(pile.keep, pile.give_up));
			}
			const $list = $('<div>', { class: 'housemajik-listings' });
			(pile.listings || []).forEach(function(listing) {
				$list.append(buildListingCard(listing));
			});
			$col.append($list);
			$root.append($col);
		});

		$('#housemajik-listings').hide();
		$('.housemajik-container').addClass('housemajik-showing-tradeoff');
		$root.css('display', 'grid');
		$('#housemajik-results').show();
	}

	function tradeoffSwap(keep, giveUp) {
		const $swap = $('<div>', { class: 'housemajik-tradeoff-swap' });
		if (keep) {
			$swap.append(
				$('<p>', { class: 'housemajik-tradeoff-keep' })
					.append($('<span>', { class: 'housemajik-tradeoff-label', text: 'You keep:' }))
					.append(document.createTextNode(' ' + keep + '.'))
			);
		}
		if (giveUp) {
			$swap.append(
				$('<p>', { class: 'housemajik-tradeoff-give' })
					.append($('<span>', { class: 'housemajik-tradeoff-label', text: 'You give up:' }))
					.append(document.createTextNode(' ' + giveUp + '.'))
			);
		}
		return $swap;
	}

	function buildListingCard(listing) {
		const photos = listingPhotos(listing);
		const why = listing.why || '';
		const garage = listing.garage ? `<span class="housemajik-listing-detail">${escapeHtml(listing.garage)}</span>` : '';
		const card = $('<div>', { class: 'housemajik-listing-card' });

		if (photos.length) {
			const stage = $('<div>', { class: 'housemajik-photo-stage' });
			stage.append(
				$('<img>', {
					src: photos[0],
					alt: listing.address,
					class: 'housemajik-listing-image'
				})
			);
			stage.append(decorativePhotoChrome());
			card.append(stage);
		}

		const content = $('<div>', { class: 'housemajik-listing-content' });

		content.append(
			$('<div>', { class: 'housemajik-listing-address', text: listing.address }),
			$('<div>', { class: 'housemajik-listing-city', text: `${listing.city}, ${listing.state} ${listing.zip}` }),
			$('<div>', { class: 'housemajik-listing-price', text: `$${formatNumber(listing.price)}` })
		);

		const details = $('<div>', { class: 'housemajik-listing-details' });
		if (isLandListing(listing)) {
			details.append($('<span>', { class: 'housemajik-listing-detail', text: 'Raw land' }));
			if (listing.lot_size) {
				details.append($('<span>', { class: 'housemajik-listing-detail', text: listing.lot_size }));
			}
		} else {
			details.append(
				$('<span>', { class: 'housemajik-listing-detail', text: `${listing.beds} Bed` }),
				$('<span>', { class: 'housemajik-listing-detail', text: `${listing.baths} Bath` })
			);
			if (garage) {
				details.append($(garage));
			}
			if (listing.lot_size && /acre/i.test(String(listing.lot_size))) {
				details.append($('<span>', { class: 'housemajik-listing-detail', text: listing.lot_size }));
			}
		}
		content.append(details);

		const tradeoff = listing.tradeoff;
		if (tradeoff && (tradeoff.keep || tradeoff.give_up)) {
			content.append(tradeoffSwap(tradeoff.keep, tradeoff.give_up));
		} else if (why) {
			content.append(
				$('<div>', { class: 'housemajik-listing-why' })
					.append(
						$('<span>', { class: 'housemajik-why-label', text: 'Why this one:' }),
						document.createTextNode(' ' + why)
					)
			);
		}

		const piles = listing.piles;
		if (!tradeoff && piles && (piles.must || piles.want || piles.never || piles.sacrificed)) {
			const pileList = $('<ul>', { class: 'housemajik-listing-piles' });
			const rows = [
				{ key: 'must', label: 'Must:' },
				{ key: 'want', label: 'Would like:' },
				{ key: 'never', label: 'Never:' },
				{ key: 'sacrificed', label: 'What is sacrificed:' }
			];
			rows.forEach(function(row) {
				const pile = piles[row.key];
				if (!pile || !pile.text) {
					return;
				}
				if ((row.key === 'never' || row.key === 'want') && (!pile.status || pile.status === 'unknown')) {
					return;
				}
				const detail = row.key === 'sacrificed'
					? (' ' + pile.text)
					: (' ' + pile.text + ' — ' + pileLabel(pile.status));
				pileList.append(
					$('<li>')
						.append($('<span>', { class: 'housemajik-pile-label', text: row.label }))
						.append(document.createTextNode(detail))
				);
			});
			if (pileList.children().length) {
				content.append(pileList);
			}
		}

		const actions = $('<div>', { class: 'housemajik-listing-actions' });
		const detailUrl = (housemajik.home_url || '').replace(/\/$/, '') + '/property/' + listing.id;
		actions.append(
			$('<a>', {
				href: detailUrl,
				class: 'housemajik-btn-link',
				text: 'View Full Details'
			})
		);
		content.append(actions);

		card.append(content);
		return card;
	}

	function ensureDetailNotes(listingId) {
		if ($('.housemajik-listing-reactions').length) {
			return;
		}

		const $main = $('.housemajik-detail-main');
		if (!$main.length) {
			return;
		}

		const $notes = $('<div>', {
			class: 'housemajik-listing-reactions',
			'data-listing-id': listingId
		});

		$notes.append(
			$('<h4>', { text: 'Your thoughts on this property (optional)' }),
			$('<textarea>', {
				name: 'what_like',
				placeholder: 'What I like about this one...',
				class: 'housemajik-reaction-like'
			}),
			$('<textarea>', {
				name: 'what_dislike',
				placeholder: 'What I don\'t like...',
				class: 'housemajik-reaction-dislike'
			}),
			$('<button>', {
				type: 'button',
				class: 'housemajik-btn housemajik-btn-secondary housemajik-reaction-save',
				text: 'Save Notes'
			})
		);

		const $location = $main.find('h2').filter(function() {
			return $(this).text().trim() === 'Location';
		}).first();

		if ($location.length) {
			$location.before($notes);
		} else {
			$main.append($notes);
		}
	}

	function handleSaveProperty(e) {
		e.preventDefault();
		const $btn = $(this);
		const listingId = $btn.data('listing-id');
		const currentlySaved = String($btn.data('saved')) === '1';
		const nextSaved = currentlySaved ? 0 : 1;

		$btn.prop('disabled', true);

		$.ajax({
			url: housemajik.ajax_url,
			type: 'POST',
			data: {
				action: 'housemajik_save_property',
				nonce: housemajik.nonce,
				listing_id: listingId,
				saved: nextSaved
			},
			success: function(response) {
				if (!response.success) {
					alert(response.data && response.data.message ? response.data.message : 'Could not save.');
					return;
				}
				const saved = !!response.data.saved;
				$btn.data('saved', saved ? 1 : 0);
				$btn.text(saved ? 'Saved' : 'Save this property');
				$btn.toggleClass('housemajik-btn-primary', !saved);
				$btn.toggleClass('housemajik-btn-secondary', saved);
				if (response.data.saved_heading) {
					$('.housemajik-saved-page h1').text(response.data.saved_heading);
				}
				if (response.data.saved_link) {
					$('.housemajik-saved-homes-search a').text(response.data.saved_link);
				}
				if (response.data.saved_link_your) {
					$('.housemajik-saved-homes-btn').text(response.data.saved_link_your);
				}
				if (!saved && $btn.closest('.housemajik-saved-page').length) {
					$btn.closest('.housemajik-listing-card').slideUp(200, function() {
						$(this).remove();
					});
				}
			},
			error: function() {
				alert('An error occurred. Please try again.');
			},
			complete: function() {
				$btn.prop('disabled', false);
			}
		});
	}

	function handleSaveReaction(e) {
		e.preventDefault();

		const $btn = $(this);
		const $container = $btn.closest('.housemajik-listing-reactions');
		const listingId = $container.data('listing-id');
		const whatLike = $container.find('.housemajik-reaction-like').val();
		const whatDislike = $container.find('.housemajik-reaction-dislike').val();
		const hasComment = $.trim(whatLike) !== '' || $.trim(whatDislike) !== '';

		$btn.prop('disabled', true).text('Saving...');

		$.ajax({
			url: housemajik.ajax_url,
			type: 'POST',
			data: {
				action: 'housemajik_save_reaction',
				nonce: housemajik.nonce,
				listing_id: listingId,
				what_like: whatLike,
				what_dislike: whatDislike
			},
			success: function(response) {
				if (response.success) {
					$btn.text('Saved ✓');
					setTimeout(function() {
						$btn.text('Save Notes');
					}, 2000);
					if (hasComment && shouldPromptAfterNote()) {
						setTimeout(showRegistrationModal, 400);
					}
				} else {
					alert(response.data.message || 'Failed to save notes.');
				}
			},
			error: function() {
				alert('An error occurred. Please try again.');
			},
			complete: function() {
				$btn.prop('disabled', false);
			}
		});
	}

	function shouldPromptAfterNote() {
		return !getCookie('housemajik_lead');
	}

	function showRegistrationModal() {
		if (!shouldPromptAfterNote()) {
			return;
		}
		const $modal = $('#housemajik-register-modal');
		if (!$modal.length) {
			return;
		}
		$modal.appendTo(document.body);
		$modal.stop(true, true).css({ display: 'flex', opacity: 0 }).animate({ opacity: 1 }, 200);
	}

	function closeModal() {
		const $modal = $('#housemajik-register-modal');
		$modal.stop(true, true).animate({ opacity: 0 }, 200, function() {
			$modal.css('display', 'none');
		});
	}

	function handleRegister(e) {
		e.preventDefault();

		const $form = $(this);
		const $submit = $('#housemajik-register-submit');
		const saveAlert = $('#reg_alert').is(':checked');

		$submit.prop('disabled', true).text('Registering...');

		const formData = $form.serialize();

		$.ajax({
			url: housemajik.ajax_url,
			type: 'POST',
			data: formData + '&action=housemajik_register&nonce=' + housemajik.nonce,
			success: function(response) {
				if (response.success) {
					setCookie('housemajik_lead', '1', 365);
					var first = String($('#reg_first_name').val() || '').trim().split(/\s+/)[0] || '';
					if (first) {
						setCookie('housemajik_buyer_name', first, 365);
					}
					closeModal();
					alert(response.data.message);
					if (saveAlert) {
						saveSearchAlert();
					}
				} else {
					alert(response.data.message || 'Registration failed.');
				}
			},
			error: function() {
				alert('An error occurred. Please try again.');
			},
			complete: function() {
				$submit.prop('disabled', false).text('Register');
			}
		});
	}

	function saveSearchAlert() {
		$.ajax({
			url: housemajik.ajax_url,
			type: 'POST',
			data: {
				action: 'housemajik_save_alert',
				nonce: housemajik.nonce,
				first_name: $('#reg_first_name').val(),
				last_name: $('#reg_last_name').val(),
				email: $('#reg_email').val(),
				phone: $('#reg_phone').val(),
				silent: 1
			}
		});
	}

	function showEmpty(message, suggestion) {
		const land = $('#land_only').is(':checked');
		const fallback = land
			? 'Nothing currently listed met every filter you set. Try a little more budget, or a nearby city.'
			: 'Nothing currently listed met every filter you set. Try one fewer bedroom, a little more budget, or a nearby city.';
		$('#housemajik-empty').find('.housemajik-empty-copy').text(message || fallback);

		const $suggest = $('#housemajik-empty-suggest');
		if (suggestion && suggestion.cta && suggestion.results && suggestion.results.length) {
			emptySuggestionApply = suggestion.apply || {};
			emptySuggestionResults = suggestion.results;
			$suggest.text(suggestion.cta).show();
		} else {
			emptySuggestionApply = null;
			emptySuggestionResults = null;
			$suggest.hide();
		}

		$('#housemajik-empty').show();
	}

	function applyEmptySuggestion() {
		const apply = emptySuggestionApply;
		if (!apply || typeof apply !== 'object') {
			return;
		}

		const $form = $('#housemajik-search');
		$.each(apply, function(name, value) {
			if (name === 'location') {
				setLocations($form, value);
				return;
			}
			const $field = $form.find('[name="' + name + '"]');
			if ($field.length) {
				$field.val(name === 'max_price' ? formatPriceDisplay(value) : value).trigger('change');
			}
		});

		applyLandOnlyMode();
		saveSearchForm();

		if (emptySuggestionResults && emptySuggestionResults.length) {
			const ready = emptySuggestionResults.slice();
			emptySuggestionApply = null;
			emptySuggestionResults = null;
			$('#housemajik-empty').hide();
			displayResults(ready);
			const target = document.getElementById('housemajik-results');
			if (target && target.scrollIntoView) {
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
			return;
		}

		$('#housemajik-search').trigger('submit');
	}

	function locationRoot($form) {
		return ($form && $form.length ? $form : $('#housemajik-search')).find('#housemajik-location');
	}

	function locationStatewide($form) {
		return locationRoot($form).attr('data-statewide') || '';
	}

	function locationChecks($form) {
		return locationRoot($form).find('[name="location[]"]');
	}

	function parseLocations(value) {
		if ($.isArray(value)) {
			return value.map(function(item) {
				return String(item).trim();
			}).filter(Boolean);
		}
		return String(value == null ? '' : value).split(/\s*,\s*/).map(function(item) {
			return item.trim();
		}).filter(Boolean);
	}

	function selectedLocations($form) {
		const cities = [];
		locationChecks($form).filter(':checked').each(function() {
			cities.push($(this).val());
		});
		return cities;
	}

	function setLocations($form, value) {
		const $checks = locationChecks($form);
		const cities = parseLocations(value).map(function(item) {
			return item.toLowerCase();
		});
		const statewide = cities.length === 1 && ['arizona', 'az', 'any'].indexOf(cities[0]) !== -1;

		$checks.prop('checked', false);
		locationRoot($form).attr('data-statewide', statewide ? 'Arizona' : '');
		if (!statewide) {
			$checks.each(function() {
				if (cities.indexOf(String(this.value).toLowerCase()) !== -1) {
					this.checked = true;
				}
			});
		}

		updateLocationSummary($form);
	}

	function updateLocationSummary($form) {
		const $root = ($form && $form.length ? $form : $('#housemajik-search')).find('#housemajik-location');
		const $toggle = $root.find('.housemajik-multiselect-toggle');
		const $value = $root.find('.housemajik-multiselect-value');
		if (!$toggle.length) {
			return;
		}

		const cities = selectedLocations($form);
		let label = 'Select cities';
		if (locationStatewide($form) === 'Arizona') {
			label = 'Arizona';
		} else if (cities.length === 1) {
			label = cities[0];
		} else if (cities.length === 2) {
			label = cities[0] + ', ' + cities[1];
		} else if (cities.length > 2) {
			label = cities[0] + ', ' + cities[1] + ' + ' + (cities.length - 2) + ' more';
		}

		$value.text(label);
		$toggle.toggleClass('is-placeholder', label === 'Select cities');
	}

	function bindLocationField() {
		const $root = $('#housemajik-location');
		if (!$root.length) {
			return;
		}

		const $toggle = $root.find('#location-toggle');
		const $panel = $root.find('.housemajik-multiselect-panel');

		function closePanel() {
			$panel.prop('hidden', true);
			$toggle.attr('aria-expanded', 'false');
		}

		function openPanel() {
			$panel.prop('hidden', false);
			$toggle.attr('aria-expanded', 'true');
		}

		$toggle.on('click', function(e) {
			e.preventDefault();
			if ($panel.prop('hidden')) {
				openPanel();
			} else {
				closePanel();
			}
		});

		$root.on('change', '[name="location[]"]', function() {
			$root.attr('data-statewide', '');
			updateLocationSummary($('#housemajik-search'));
		});

		$(document).on('click.housemajikLocation', function(e) {
			if (!$root.is(e.target) && $root.has(e.target).length === 0) {
				closePanel();
			}
		});

		$(document).on('keydown.housemajikLocation', function(e) {
			if (e.key === 'Escape') {
				closePanel();
			}
		});

		updateLocationSummary($('#housemajik-search'));
	}

	function parsePrice(value) {
		const digits = String(value == null ? '' : value).replace(/[^0-9]/g, '');
		if (!digits) {
			return '';
		}
		return String(parseInt(digits, 10));
	}

	function formatPriceDisplay(value) {
		const raw = parsePrice(value);
		return raw ? formatNumber(raw) : '';
	}

	function bindPriceField() {
		const $input = $('#max_price');
		if (!$input.length) {
			return;
		}

		$input.on('input', function() {
			const formatted = formatPriceDisplay(this.value);
			if (this.value !== formatted) {
				this.value = formatted;
			}
		});

		$input.on('blur', function() {
			this.value = formatPriceDisplay(this.value);
		});
	}

	function formatNumber(num) {
		return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function pileLabel(status) {
		if (status === 'met') {
			return 'Met';
		}
		if (status === 'missed') {
			return 'Not in this listing';
		}
		if (status === 'yes') {
			return 'Yes';
		}
		if (status === 'clear') {
			return 'Clear';
		}
		if (status === 'hit') {
			return 'This one has that';
		}
		return 'Not in the listing facts';
	}

	function escapeHtml(text) {
		const map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}

	function setCookie(name, value, days) {
		var expires = '';
		if (days) {
			var date = new Date();
			date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
			expires = '; expires=' + date.toUTCString();
		}
		document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
	}

	function expireCookie(name) {
		document.cookie = name + '=; path=/; max-age=0; SameSite=Lax';
	}

	function getCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : '';
	}

})(jQuery);
