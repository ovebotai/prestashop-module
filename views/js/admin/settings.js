/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

/* global jQuery */
// Settings page. The config object is injected by PrestaShop through
// Media::addJsDef as the global window.ovebotaiSettings; it's read
// defensively so a missing key can't throw.
(function ($) {
	'use strict';

	var cfg  = window.ovebotaiSettings || {};
	var i18n = cfg.i18n || {};

	// ── AJAX helpers ─────────────────────────────────────────────────────────

	// Single admin entry point (index.php?controller=AdminOvebotai&token=...&ajax=1);
	// every call just appends its action name.
	function ajaxUrl(action) {
		return String(cfg.ajaxUrl || '').replace(/&amp;/g, '&') + '&action=' + action;
	}

	// The controller answers {error: "..."} (e.g. permission denied) rather
	// than {success: false, message: "..."} for access failures - both are
	// failures, and both carry a text worth showing.
	function responseError(resp) {
		if (!resp) { return ''; }
		if (resp.error) { return String(resp.error); }
		if (!resp.success && resp.message) { return String(resp.message); }
		return '';
	}

	// A non-2xx answer can still carry a JSON body (PrestaShop's own
	// permission denial does) - prefer its text over the generic error.
	function xhrError(jqXHR) {
		var json = jqXHR && jqXHR.responseJSON;
		if (!json && jqXHR && jqXHR.responseText) {
			try { json = JSON.parse(jqXHR.responseText); } catch (err) { json = null; }
		}
		return responseError(json) || i18n.error;
	}

	// json_encode may hand us true/false, 1/0 or "1"/"0" depending on how the
	// controller built the value - normalise all of them.
	function toBool(v) {
		return v === true || v === 1 || v === '1' || v === 'true';
	}

	// Tiny URLSearchParams stand-in (that API is missing on older BO browsers).
	function getQueryParam(name) {
		var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.search);
		return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : null;
	}

	$(function () {

		// ── Unsaved changes guard ────────────────────────────────────────────
		// No change-tracking state to keep in sync - just diff the form's
		// current serialization against its initial one at the moment the
		// browser actually asks, so an edit that's later undone (typed then
		// untyped) doesn't leave a stale "dirty" flag behind.

		var $oveForm    = $('#oveSettingsForm');
		var $oveSaveBtn = $('#oveSaveBtn');
		var initialSerialized = $oveForm.serialize();

		$(window).on('beforeunload', function (e) {
			if ($oveForm.serialize() !== initialSerialized) {
				var native = e.originalEvent || e;
				if (native.preventDefault) { native.preventDefault(); }
				native.returnValue = '';
				return '';
			}
		});

		// Pulse the Save button while there are unsaved changes, so it's
		// obvious there's something to do before leaving the page.
		function updateSaveAttention() {
			$oveSaveBtn.toggleClass('ovebotai-attn', $oveForm.serialize() !== initialSerialized);
		}

		$oveForm.on('input change', 'input, select', updateSaveAttention);

		// ── Switch label helper ──────────────────────────────────────────────

		function setSwitchLabel(inputSel, labelSel) {
			$(labelSel).text($(inputSel).is(':checked') ? i18n.enabled : i18n.disabled);
		}

		// After a successful save the controller reports the switch values it
		// actually ended up with (resp.effective = {products_builtin,
		// products_recommend, order_enabled}) - the Ovebot.ai account can
		// refuse or downgrade one of them - so the switches are re-synced to
		// that truth BEFORE the "saved" snapshot is taken, otherwise the form
		// would immediately read as dirty again (and the beforeunload guard
		// would nag about changes the merchant never made).
		function applyEffective(effective) {
			if (!effective || typeof effective !== 'object') { return; }

			var switches = {
				products_builtin:   ['#oveProductsBuiltin',   '#oveProductsBuiltinLbl'],
				products_recommend: ['#oveProductsRecommend', '#oveProductsRecommendLbl'],
				order_enabled:      ['#oveOrderEnabled',      '#oveOrderEnabledLbl']
			};

			for (var key in switches) {
				if (!Object.prototype.hasOwnProperty.call(switches, key)) { continue; }
				if (!Object.prototype.hasOwnProperty.call(effective, key)) { continue; }
				$(switches[key][0]).prop('checked', toBool(effective[key]));
				setSwitchLabel(switches[key][0], switches[key][1]);
			}

			if (Object.prototype.hasOwnProperty.call(effective, 'products_builtin')) {
				$('#oveFeedUrlField').toggle(toBool(effective.products_builtin));
			}
		}

		// ── Save form ────────────────────────────────────────────────────────

		var oveSaveBtnText = $oveSaveBtn.text();

		$oveForm.on('submit', function (e) {
			e.preventDefault();
			var $btn = $oveSaveBtn.prop('disabled', true).removeClass('ovebotai-attn').text(i18n.saving);

			function restoreBtn() {
				updateSaveAttention();
				$btn.prop('disabled', false).text(oveSaveBtnText);
			}

			$.ajax({
				url: ajaxUrl('SaveSettings'),
				type: 'POST',
				dataType: 'json',
				data: $oveForm.serialize()
			})
				.done(function (resp) {
					var ok          = !!(resp && resp.success);
					var msg         = (resp && (resp.message || resp.error)) || (ok ? i18n.saved : i18n.error);
					var warnings    = ok ? resp.warnings : null;
					var hasWarnings = !!(warnings && warnings.length);
					// needs_reconnect has no warnings list of its own - it's a single
					// caveat on the save itself, so it still takes over the main notice.
					var needsReconnect = ok && !!resp.needs_reconnect && !hasWarnings;

					showNotice(ok && !needsReconnect, msg, needsReconnect ? 'warning' : null);
					showWarnings(hasWarnings ? warnings : null);

					if (ok) {
						applyEffective(resp.effective);
						// Saved state is now the new baseline - further edits are
						// judged against it, not the page-load snapshot.
						initialSerialized = $oveForm.serialize();
					}

					// Only a clean save bounces back to the dashboard. With
					// warnings or a reconnect caveat the merchant has to be able
					// to read them, so the page stays put.
					var clean = ok && !hasWarnings && !resp.needs_reconnect;
					if (clean && cfg.dashboardUrl) {
						setTimeout(function () { window.location.href = cfg.dashboardUrl; }, 1200);
					} else {
						restoreBtn();
					}
				})
				.fail(function (jqXHR) {
					showNotice(false, xhrError(jqXHR));
					showWarnings(null);
					restoreBtn();
				});
		});

		// ── Toggle chat status label ─────────────────────────────────────────

		$('#oveChatStatus').on('change', function () {
			setSwitchLabel('#oveChatStatus', '#oveChatStatusLbl');
		});

		// ── Toggle products-recommend label ──────────────────────────────────

		$('#oveProductsRecommend').on('change', function () {
			setSwitchLabel('#oveProductsRecommend', '#oveProductsRecommendLbl');
		});

		// ── Toggle built-in feed label + feed URL row ────────────────────────

		$('#oveProductsBuiltin').on('change', function () {
			var enabled = $(this).is(':checked');
			$('#oveProductsBuiltinLbl').text(enabled ? i18n.enabled : i18n.disabled);
			$('#oveFeedUrlField').toggle(enabled);
		});

		// ── Toggle order-tracking-enabled label ──────────────────────────────

		$('#oveOrderEnabled').on('change', function () {
			setSwitchLabel('#oveOrderEnabled', '#oveOrderEnabledLbl');
		});

		// ── Appearance panel toggle ──────────────────────────────────────────

		$('#oveAppearanceToggle').on('click', function () {
			var $p = $('#oveAppearancePanel');
			var open = $p.is(':visible');
			$p.slideToggle(180);
			// Material Icons are ligatures - the icon IS the element's text -
			// so swap the name rather than toggling classes.
			$('#oveAppearanceToggleIcon').text(open ? 'expand_more' : 'expand_less');
		});

		// Sync color picker <-> text input.
		$('#ove_color_picker').on('input', function () {
			$('[name="widget_accent_color"]').val($(this).val());
			updateSaveAttention();
		});
		$('[name="widget_accent_color"]').on('input', function () {
			var v = $(this).val();
			if (/^#[0-9a-f]{6}$/i.test(v)) {
				$('#ove_color_picker').val(v);
			}
		});

		// ── Copy buttons ─────────────────────────────────────────────────────

		$(document).on('click', '.ovebotai-copy-btn', function () {
			var $btn     = $(this);
			var targetId = $btn.data('target');
			var el       = document.getElementById(targetId);
			if (!el) { return; }
			var val = $(el).val();

			// Legacy path: select the (readonly) input and copy the selection.
			function fallbackCopy() {
				try {
					el.focus();
					el.select();
					if (el.setSelectionRange) { el.setSelectionRange(0, String(val).length); }
					document.execCommand('copy');
				} catch (err) {
					// Nothing more we can do - the text is at least selected.
				}
			}

			// navigator.clipboard only exists in secure contexts (https /
			// localhost); on a plain-http back office it's undefined, so fall
			// back to select + execCommand('copy').
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(val).then(function () {
					// brief label swap below
				}, fallbackCopy);
			} else {
				fallbackCopy();
			}

			var orig = $btn.text();
			$btn.text(i18n.copied);
			setTimeout(function () { $btn.text(orig); }, 1800);
		});

		// ── Regenerate feed hash ─────────────────────────────────────────────

		$('.ovebotai-regen-hash-btn').on('click', function () {
			if (!window.confirm(i18n.confirmRegenHash)) { return; }
			var $btn = $(this).prop('disabled', true);
			$.ajax({ url: ajaxUrl('RegenFeedHash'), type: 'POST', dataType: 'json' })
				.done(function (resp) {
					if (resp && resp.success) {
						$('#oveFeedUrl').val(resp.url);
						showNotice(true, resp.message || i18n.saved);
					} else {
						showNotice(false, responseError(resp) || i18n.error);
					}
				})
				.fail(function (jqXHR) { showNotice(false, xhrError(jqXHR)); })
				.always(function () { $btn.prop('disabled', false); });
		});

		// ── Regenerate API credentials ───────────────────────────────────────

		$('.ovebotai-regen-creds-btn').on('click', function () {
			if (!window.confirm(i18n.confirmRegenCreds)) { return; }
			var $btn = $(this).prop('disabled', true);
			$.ajax({ url: ajaxUrl('RegenOrderCreds'), type: 'POST', dataType: 'json' })
				.done(function (resp) {
					if (resp && resp.success) {
						$('#oveApiUser').val(resp.user);
						$('#oveApiPass').val(resp.pass);
						showNotice(true, resp.message || i18n.saved);
					} else {
						showNotice(false, responseError(resp) || i18n.error);
					}
				})
				.fail(function (jqXHR) { showNotice(false, xhrError(jqXHR)); })
				.always(function () { $btn.prop('disabled', false); });
		});

		// ── Notice helper ────────────────────────────────────────────────────

		function showNotice(ok, msg, type) {
			var cls = type === 'warning' ? 'ovebotai-notice-warning' : (ok ? 'ovebotai-notice-success' : 'ovebotai-notice-error');
			var $n  = $('#oveSettingsNotice');
			$n.removeClass('ovebotai-notice-success ovebotai-notice-error ovebotai-notice-warning')
				.addClass(cls)
				.html('<p>' + msg + '</p>')
				.slideDown(180);
			$('html, body').animate({ scrollTop: Math.max(0, $n.offset().top - 40) }, 300);
			clearTimeout($n.data('timer'));
			var delay = type === 'warning' ? 7000 : 4000;
			$n.data('timer', setTimeout(function () { $n.slideUp(180); }, delay));
		}

		function showWarnings(warnings) {
			var $w = $('#oveSettingsWarnings');
			clearTimeout($w.data('timer'));
			if (warnings && warnings.length) {
				$w.html('<p>' + warnings.join('<br>') + '</p>').slideDown(180);
				$w.data('timer', setTimeout(function () { $w.slideUp(180); }, 9000));
			} else {
				$w.slideUp(180);
			}
		}

		// (The OpenCart build had a "not connected -> back to the dashboard"
		// redirect here. The PrestaShop controller never renders this view
		// while disconnected, so there's nothing to guard against.)

		// ── Highlight a field via ?highlight=<id> ────────────────────────────
		// Generic, not chat-specific - anything linking here can point at any
		// field id (e.g. the dashboard's "chat disabled" card uses
		// oveChatStatus) and have it scrolled into view + pulsed a few times
		// so it's obvious what to look at, instead of leaving the merchant to
		// hunt for it on a page full of fieldsets.
		var highlightId = getQueryParam('highlight');
		if (highlightId) {
			var $target = $(document.getElementById(highlightId));
			if ($target.length) {
				// Prefer the actual visible control (e.g. the toggle switch
				// itself, not its whole field row) so the pulse points right
				// at the thing to click, not the whole surrounding block.
				var $highlight = $target.closest('.ovebotai-switch');
				if (!$highlight.length) { $highlight = $target.closest('.ovebotai-field'); }
				if (!$highlight.length) { $highlight = $target; }
				$highlight.addClass('ovebotai-highlight-pulse');

				var rect = $highlight[0].getBoundingClientRect();
				var inViewport = rect.top >= 0 && rect.bottom <= (window.innerHeight || document.documentElement.clientHeight);
				if (!inViewport) {
					$('html, body').animate({ scrollTop: Math.max(0, $highlight.offset().top - 80) }, 300);
				}

				setTimeout(function () { $highlight.removeClass('ovebotai-highlight-pulse'); }, 4500);
			}
		}
	});

}(jQuery));
