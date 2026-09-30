/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

/* global jQuery */
// Common Ovebot.ai admin script - loaded on every module view (setup,
// dashboard, settings). View-specific behaviour lives in its own file
// (setup.js / settings.js); shared, cross-view behaviour belongs here.
(function ($) {
	'use strict';

	window.Ovebotai = window.Ovebotai || {};

	$(function () {
		// Any link/button with data-ovebotai-confirm="message" asks for
		// confirmation before proceeding - used by the Disconnect links so a
		// misclick can't sever the Ovebot.ai connection.
		$(document).on('click', '[data-ovebotai-confirm]', function (e) {
			var message = $(this).data('ovebotai-confirm');
			if (message && !window.confirm(message)) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
		});
	});

}(jQuery));
