/* global jQuery */
(function ($) {
	'use strict';
	$(function () {
		const root = document.querySelector('.wcmcf');
		if (!root) return;
		const mode = root.querySelector('#wcmcf-selection-type');
		function showMode() {
			root.querySelectorAll('[data-mode]').forEach(function (field) {
				field.hidden = field.dataset.mode !== mode.value;
				// SelectWoo measures its search input while initializing. Recalculate
				// when a field becomes visible after being initialized inside a hidden panel.
				if (!field.hidden) $(field).find('select.enhanced').trigger('change.select2');
			});
		}
		mode.addEventListener('change', showMode);
		showMode();
		root.querySelector('.wcmcf-advanced').addEventListener('toggle', function () {
			if (this.open) $(this).find('select.enhanced').trigger('change.select2');
		});
		// SelectWoo supplies search, keyboard navigation, selection, and focus handling.
		// Connect the rendered search inputs to the same visible native field labels.
		function labelSearches() {
			$(root).find('select[multiple]').each(function () {
				const label = root.querySelector('label[for="' + this.id + '"]');
				if (!label) return;
				$(this).next('.select2-container').find('[role="combobox"], .select2-search__field').attr('aria-labelledby', label.id);
				$(this).next('.select2-container').find('[role="combobox"], .select2-search__field').attr('aria-describedby', this.getAttribute('aria-describedby'));
				$(this).next('.select2-container').find('[role="combobox"], .select2-search__field').attr('aria-invalid', this.getAttribute('aria-invalid'));
			});
		}
		$(document.body).on('wc-enhanced-select-init', labelSearches);
		labelSearches();
		root.querySelector('[data-copy-url]').addEventListener('click', async function () {
			const input = root.querySelector('#wcmcf-feed-url');
			const status = root.querySelector('[data-copy-status]');
			try {
				await navigator.clipboard.writeText(input.value);
				status.textContent = 'Feed URL copied.';
				this.textContent = 'Copied';
			} catch (error) {
				input.focus();
				input.select();
				status.textContent = 'Feed URL selected. Press Control+C or Command+C to copy.';
			}
		});
	});
})(jQuery);
