/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

/* global jQuery */
// Setup wizard (Connect -> Website pages -> Products -> Go live). The config
// object is injected by PrestaShop through Media::addJsDef as the global
// window.ovebotaiSetup; it's read defensively so a missing key can't throw.
(function ($) {
	'use strict';

	var cfg  = window.ovebotaiSetup || {};
	var i18n = cfg.i18n || {};

	// [1,2,3,4] - Connect, Website pages, Products, Go live.
	var stepsSeq = (Array.isArray(cfg.stepsSequence) && cfg.stepsSequence.length)
		? cfg.stepsSequence.map(function (n) { return parseInt(n, 10); })
		: [1, 2, 3, 4];
	var current     = parseInt(cfg.initialStep, 10) || stepsSeq[0];
	var isConnected = cfg.isConnected === true || parseInt(cfg.isConnected, 10) === 1;
	var navigating  = false;

	// The step whose "Next" triggers sync + advances to Finish - second-to-last
	// in the sequence (last is always the Finish panel, step 4).
	var finishStep = stepsSeq[stepsSeq.length - 2];

	function posOf(step) {
		var i = stepsSeq.indexOf(step);
		return i === -1 ? 0 : i;
	}

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

	$(function () {
		renderStep(current);

		$('#oveNextBtn').on('click', handleNext);
		$('#ovePrevBtn').on('click', handlePrev);
		$('input[name="products_mode"]').on('change', reflectProductMode);
		// The OAuth error notice (if any) is rendered server-side by the
		// controller - nothing to inject from here.
	});

	// ── Step navigation ──────────────────────────────────────────────────────

	function renderStep(step) {
		current = step;
		var pos = posOf(step);

		$('.ovebotai-panel').hide();
		$('.ovebotai-panel[data-panel="' + step + '"]').show();

		$('.ovebotai-step-dot').each(function () {
			var n = parseInt($(this).data('step'), 10);
			$(this).toggleClass('is-active', n === step);
			$(this).toggleClass('is-done', posOf(n) < pos);
		});

		var pct = stepsSeq.length > 1 ? (pos / (stepsSeq.length - 1)) * 100 : 100;
		$('#oveProgressBar').css('width', pct + '%');

		var isLast = pos === stepsSeq.length - 1;

		$('#oveSetupNav').toggle(!isLast);
		$('#ovePrevBtn').toggle(pos > 0);
		$('#oveNextBtn').toggle(true);

		// Step 1: hide Next until connected.
		if (step === 1) {
			$('#oveNextBtn').toggle(isConnected);
		}

		// Last content step before Finish: relabel Next to "Finish setup".
		if (step === finishStep) {
			$('#oveNextBtn').text(i18n.finish + ' →');
			if (step === 3) { updateProductMessage(); reflectProductMode(); }
		} else {
			$('#oveNextBtn').text(i18n.next + ' →');
		}
	}

	function handleNext() {
		if (navigating) { return; }

		if (current === 2) {
			syncPages();
			return;
		}

		navigating = true;
		setTimeout(function () { navigating = false; }, 500);

		if (current === 4) {
			// Next visible on step 4 only after a failed sync - retry.
			doSync();
		} else if (current === finishStep) {
			renderStep(4);
			doSync();
		} else {
			renderStep(stepsSeq[posOf(current) + 1]);
		}
	}

	function handlePrev() {
		if (navigating) { return; }
		navigating = true;
		setTimeout(function () { navigating = false; }, 500);
		renderStep(stepsSeq[posOf(current) - 1]);
	}

	// ── Product count message ─────────────────────────────────────────────────

	function updateProductMessage() {
		var counts = cfg.productCounts;
		if (!counts) { return; }

		if (parseInt(counts.total, 10) === 0) {
			$('#oveProductMsg').html(i18n.noProducts);
			// Nothing to count, so the "per variation" explanation is noise.
			$('#oveProductVariantsNote').hide();
			return;
		}
		$('#oveProductVariantsNote').show();

		$('#oveProductMsg').html('<span class="ovebotai-count-badge">' + counts.feed_count + '</span> ' + i18n.productsWillBeIndexed);
	}

	// Dim the product count while a custom feed is selected (built-in off): the
	// count reflects OUR feed, so it's informational-only when they'll bring
	// their own. Full opacity when the built-in feed is the source.
	function reflectProductMode() {
		var external = $('#oveProductsExternal').is(':checked');
		$('#oveProductMsg, #oveProductVariantsNote').toggleClass('ovebotai-msg-dimmed', external);
	}

	// ── Step 2: page sync ────────────────────────────────────────────────────

	// Fires on every "Next" click from step 2. Whatever the server reports as
	// unsent (real error, or blocked by the kb_limit quota) gets unchecked and
	// flagged inline; the button stays put on step 2 in that case, so the same
	// click that "failed" is really just a cleanup pass - the very next click
	// (now with those boxes unchecked) goes through and advances normally.
	function syncPages() {
		navigating = true;

		$('.ovebotai-page-error').hide().text('').removeClass('is-success');
		$('#oveKbLimitNotice').hide().text('');

		var pageIds = [];
		$('input[name="kb_pages[]"]:checked').each(function () {
			pageIds.push(String($(this).val()));
		});

		$('#oveNextBtn').prop('disabled', true).text(i18n.syncingPages);
		$('#ovePrevBtn').prop('disabled', true);

		$.ajax({
			url: ajaxUrl('SyncPages'),
			type: 'POST',
			dataType: 'json',
			// jQuery serialises the array as page_ids[]=1&page_ids[]=2, which
			// PHP reads back as $_POST['page_ids'].
			data: { page_ids: pageIds }
		})
			.done(function (resp) {
				navigating = false;
				$('#oveNextBtn').prop('disabled', false);
				$('#ovePrevBtn').prop('disabled', false);

				if (!resp || !resp.success) {
					$('#oveKbLimitNotice').text(responseError(resp) || i18n.error).show();
					renderStep(2);
					return;
				}

				applyPageFailures(resp.failed || {});
				applyPageFailures(indexKbLimitIds(resp.kb_limit_ids || []));

				if (resp.kb_limit) {
					$('#oveKbLimitNotice').text(resp.kb_limit).show();
				}

				if (resp.clean) {
					renderStep(3);
				} else {
					// Stay on step 2 - checkboxes are already cleaned up above.
					// Whatever's still checked out of this attempt actually went
					// through fine - flag it green so it reads as "this one's
					// done", not lumped in with the failures above.
					var failedIds = Object.keys(resp.failed || {}).concat((resp.kb_limit_ids || []).map(String));
					var succeededIds = pageIds.filter(function (id) { return failedIds.indexOf(id) === -1; });
					applyPageSuccess(succeededIds);
					renderStep(2);
				}
			})
			.fail(function (jqXHR) {
				navigating = false;
				$('#oveNextBtn').prop('disabled', false);
				$('#ovePrevBtn').prop('disabled', false);
				// renderStep(2) also restores the "Next" label overwritten by
				// the "Syncing pages..." progress text.
				renderStep(2);
				$('#oveKbLimitNotice').text(xhrError(jqXHR)).show();
			});
	}

	// kb_limit_ids has no per-page message of its own (it's the same quota
	// error for all of them). The banner shows the API's message exactly as
	// received (see the caller) - under each affected checkbox this uses its
	// own separate, static string instead of altering/reusing that API text.
	function indexKbLimitIds(ids) {
		var map = {};
		ids.forEach(function (id) {
			map[id] = i18n.kbLimitPageSkipped;
		});
		return map;
	}

	function applyPageFailures(failedMap) {
		Object.keys(failedMap).forEach(function (pageId) {
			var $row = $('.ovebotai-page-row[data-page-id="' + pageId + '"]');
			$row.find('input[name="kb_pages[]"]').prop('checked', false);
			$row.find('.ovebotai-page-error').removeClass('is-success').text(failedMap[pageId]).show();
		});
	}

	// Only called when the attempt as a whole wasn't clean (some pages
	// failed) - flags the pages that stayed checked as actually synced this
	// round, same top-right spot as the error text but green, so it's clear
	// they don't need re-sending on the next "Next" click.
	function applyPageSuccess(pageIds) {
		pageIds.forEach(function (pageId) {
			var $row = $('.ovebotai-page-row[data-page-id="' + pageId + '"]');
			$row.find('.ovebotai-page-error').addClass('is-success').text(i18n.pageUpdated).show();
		});
	}

	// ── Finish (go live) ─────────────────────────────────────────────────────

	function doSync() {
		$('#oveSetupNav').hide();
		$('#oveSyncIdle').hide();
		$('#oveSyncLoading').show();
		$('#oveSyncError').hide();

		// products_builtin: 1 = our built-in feed is the product source,
		// 0 = the merchant supplies their own feed in the Ovebot.ai account.
		var productsBuiltin = $('#oveProductsIntegrated').is(':checked') ? 1 : 0;

		$.ajax({
			url: ajaxUrl('Finish'),
			type: 'POST',
			dataType: 'json',
			data: { products_builtin: productsBuiltin }
		})
			.done(function (resp) {
				$('#oveSyncLoading').hide();
				if (resp && resp.success) {
					$('#oveSyncDone').show();
					// Every dot (including the last) turns green.
					$('.ovebotai-step-dot').removeClass('is-active').addClass('is-done');
				} else {
					showSyncError(responseError(resp) || i18n.error);
				}
			})
			.fail(function (jqXHR) {
				$('#oveSyncLoading').hide();
				showSyncError(xhrError(jqXHR));
			});
	}

	function showSyncError(msg) {
		$('#oveSyncErrorMsg').html('<p>' + escapeHtml(msg).replace(/\n/g, '<br>') + '</p>');
		$('#oveSyncError').show();
		$('#oveNextBtn').text(i18n.retry).show();
		$('#ovePrevBtn').show();
		$('#oveSetupNav').show();
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

}(jQuery));
