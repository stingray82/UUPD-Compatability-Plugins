<?php
/**
 * Plugin Name: SmartPay ↔ UUPD
 * Description: Maps WPSmartPay products/variations to UUPD updates/packages and synchronises UUPD licence lifecycle with SmartPay payments and subscriptions.
 * Version: 0.2.0
 * Author: Reallyusefulplugins.com
 */

defined( 'ABSPATH' ) || exit;

const SPUUPD_OPTION = 'spuupd_mappings';


/**
 * --------------------------------------------------------------------------
 * Global subscription grace-period settings
 * --------------------------------------------------------------------------
 *
 * Stored as normal WordPress options so they live with the UUPD Server
 * settings rather than individual SmartPay mappings.
 */
const SPUUPD_GRACE_MODE_OPTION   = 'uupd_subscription_grace_mode';
const SPUUPD_GRACE_DAYS_OPTION   = 'uupd_subscription_grace_days';
const SPUUPD_GRACE_ACTION_OPTION = 'uupd_subscription_grace_action';

function spuupd_grace_mode(): string {
	$allowed = array( 'immediate', 'paid_through', '3', '7', '14', '30', 'custom' );
	$value   = sanitize_key( (string) get_option( SPUUPD_GRACE_MODE_OPTION, 'paid_through' ) );

	return in_array( $value, $allowed, true ) ? $value : 'paid_through';
}

function spuupd_grace_days(): int {
	$mode = spuupd_grace_mode();

	if ( in_array( $mode, array( '3', '7', '14', '30' ), true ) ) {
		return absint( $mode );
	}

	if ( 'custom' === $mode ) {
		return max( 1, min( 365, absint( get_option( SPUUPD_GRACE_DAYS_OPTION, 7 ) ) ) );
	}

	return 0;
}

function spuupd_grace_action(): string {
	$value = sanitize_key( (string) get_option( SPUUPD_GRACE_ACTION_OPTION, 'expired' ) );
	return in_array( $value, array( 'suspended', 'expired' ), true ) ? $value : 'expired';
}

/**
 * Save the bridge-owned fields when the native UUPD Server settings form is
 * submitted. UUPD already owns and verifies uupd_settings_nonce.
 */
add_action(
	'admin_init',
	function () {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if (
			empty( $_POST['uupd_settings_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['uupd_settings_nonce'] ) ),
				'uupd_settings_save'
			)
		) {
			return;
		}

		$mode = sanitize_key( wp_unslash( $_POST[ SPUUPD_GRACE_MODE_OPTION ] ?? 'paid_through' ) );
		if ( ! in_array( $mode, array( 'immediate', 'paid_through', '3', '7', '14', '30', 'custom' ), true ) ) {
			$mode = 'paid_through';
		}

		$days = max(
			1,
			min(
				365,
				absint( $_POST[ SPUUPD_GRACE_DAYS_OPTION ] ?? 7 )
			)
		);

		$action = sanitize_key( wp_unslash( $_POST[ SPUUPD_GRACE_ACTION_OPTION ] ?? 'expired' ) );
		if ( ! in_array( $action, array( 'suspended', 'expired' ), true ) ) {
			$action = 'expired';
		}

		update_option( SPUUPD_GRACE_MODE_OPTION, $mode, false );
		update_option( SPUUPD_GRACE_DAYS_OPTION, $days, false );
		update_option( SPUUPD_GRACE_ACTION_OPTION, $action, false );
	}
);

/**
 * Inject the grace policy controls into the existing:
 * Settings → UUPD Server
 * options-general.php?page=uupd-server
 *
 * This keeps the setting in the native UUPD form without modifying UUPD files.
 */
add_action(
	'admin_footer',
	function () {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( 'uupd-server' !== $page ) {
			return;
		}

		$mode   = spuupd_grace_mode();
		$days   = max( 1, absint( get_option( SPUUPD_GRACE_DAYS_OPTION, 7 ) ) );
		$action = spuupd_grace_action();
		?>
		<script>
		(function () {
			'use strict';

			const form = document.querySelector('.wrap form[method="post"]');
			const table = form ? form.querySelector('table.form-table') : null;

			if (!form || !table || document.getElementById('uupd_subscription_grace_mode')) {
				return;
			}

			const heading = document.createElement('tr');
			heading.innerHTML =
				'<th colspan="2" style="padding-top:28px;padding-bottom:4px;">' +
					'<h2 style="margin:0;">Subscription Grace Period</h2>' +
					'<p class="description" style="font-weight:400;margin-top:6px;">' +
						'Controls UUPD licence access when a SmartPay subscription renewal fails.' +
					'</p>' +
				'</th>';

			const modeRow = document.createElement('tr');
			modeRow.innerHTML =
				'<th scope="row"><label for="uupd_subscription_grace_mode">Failed renewal</label></th>' +
				'<td>' +
					'<select id="uupd_subscription_grace_mode" name="uupd_subscription_grace_mode">' +
						'<option value="immediate">Suspend / expire immediately</option>' +
						'<option value="paid_through">Keep active until current licence expiry</option>' +
						'<option value="3">3-day grace period after paid-through expiry</option>' +
						'<option value="7">7-day grace period after paid-through expiry</option>' +
						'<option value="14">14-day grace period after paid-through expiry</option>' +
						'<option value="30">30-day grace period after paid-through expiry</option>' +
						'<option value="custom">Custom grace period after paid-through expiry</option>' +
					'</select>' +
					'<p class="description">Fixed grace periods are added after the customer’s existing paid-through date, not from the failed-charge date.</p>' +
				'</td>';

			const customRow = document.createElement('tr');
			customRow.id = 'spuupd-custom-grace-row';
			customRow.innerHTML =
				'<th scope="row"><label for="uupd_subscription_grace_days">Custom grace days</label></th>' +
				'<td>' +
					'<input type="number" min="1" max="365" class="small-text" id="uupd_subscription_grace_days" name="uupd_subscription_grace_days" /> days' +
					'<p class="description">Used only when “Custom grace period” is selected.</p>' +
				'</td>';

			const actionRow = document.createElement('tr');
			actionRow.innerHTML =
				'<th scope="row"><label for="uupd_subscription_grace_action">After grace period</label></th>' +
				'<td>' +
					'<select id="uupd_subscription_grace_action" name="uupd_subscription_grace_action">' +
						'<option value="expired">Expire licence</option>' +
						'<option value="suspended">Suspend licence</option>' +
					'</select>' +
					'<p class="description">The licence becomes invalid after the grace window. “Expired” is recommended for normal subscription expiry; “Suspended” is useful when you want an administrative recovery state.</p>' +
				'</td>';

			table.append(heading, modeRow, customRow, actionRow);

			const mode = document.getElementById('uupd_subscription_grace_mode');
			const days = document.getElementById('uupd_subscription_grace_days');
			const action = document.getElementById('uupd_subscription_grace_action');

			mode.value = <?php echo wp_json_encode( $mode ); ?>;
			days.value = <?php echo wp_json_encode( (string) $days ); ?>;
			action.value = <?php echo wp_json_encode( $action ); ?>;

			function toggleCustom() {
				customRow.style.display = mode.value === 'custom' ? '' : 'none';
			}

			mode.addEventListener('change', toggleCustom);
			toggleCustom();
		})();
		</script>
		<?php
	},
	120
);

/**
 * --------------------------------------------------------------------------
 * Mapping storage
 * --------------------------------------------------------------------------
 */

function spuupd_get_mappings(): array {
	$value = get_option( SPUUPD_OPTION, array() );
	return is_array( $value ) ? $value : array();
}

function spuupd_get_mapping( int $smartpay_item_id ): array {
	$all = spuupd_get_mappings();
	$key = (string) absint( $smartpay_item_id );
	return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
}

function spuupd_save_mapping( int $smartpay_item_id, array $mapping ): bool {
	$smartpay_item_id = absint( $smartpay_item_id );
	if ( ! $smartpay_item_id ) {
		return false;
	}

	$all = spuupd_get_mappings();

	$clean = array(
		'update_id'        => absint( $mapping['update_id'] ?? 0 ),
		'package_id'       => sanitize_text_field( $mapping['package_id'] ?? '' ),
		'activation_limit' => max( 0, absint( $mapping['activation_limit'] ?? 0 ) ),
	);

	// Selecting "None" removes the mapping.
	if ( ! $clean['update_id'] ) {
		unset( $all[ (string) $smartpay_item_id ] );
	} else {
		$all[ (string) $smartpay_item_id ] = $clean;
	}

	return update_option( SPUUPD_OPTION, $all, false );
}

/**
 * Variation mappings take precedence. If none exists, fall back to the
 * variation's parent SmartPay product.
 */
function spuupd_resolve_mapping( int $smartpay_item_id ): array {
	$smartpay_item_id = absint( $smartpay_item_id );

	if ( ! $smartpay_item_id ) {
		return array();
	}

	$direct = spuupd_get_mapping( $smartpay_item_id );
	if ( ! empty( $direct['update_id'] ) ) {
		$direct['mapped_smartpay_id'] = $smartpay_item_id;
		return $direct;
	}

	if ( class_exists( '\SmartPay\Models\Product' ) ) {
		try {
			$product = \SmartPay\Models\Product::find( $smartpay_item_id );
			$parent_id = $product ? absint( $product->parent_id ?? 0 ) : 0;

			if ( $parent_id ) {
				$parent = spuupd_get_mapping( $parent_id );
				if ( ! empty( $parent['update_id'] ) ) {
					$parent['mapped_smartpay_id'] = $parent_id;
					return $parent;
				}
			}
		} catch ( \Throwable $e ) {
			// Mapping simply remains unresolved.
		}
	}

	return array();
}

/**
 * --------------------------------------------------------------------------
 * UUPD catalogue
 * --------------------------------------------------------------------------
 */

function spuupd_get_updates_catalogue(): array {
	$updates = get_posts(
		array(
			'post_type'      => 'update',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$out = array();

	foreach ( $updates as $update ) {
		$packages = maybe_unserialize( get_post_meta( $update->ID, 'packages', true ) );
		$packages = is_array( $packages ) ? $packages : array();

		$clean_packages = array();

		foreach ( $packages as $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}

			$id = sanitize_text_field( $package['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$clean_packages[] = array(
				'id'               => $id,
				'label'            => sanitize_text_field( $package['label'] ?? $id ),
				'activation_limit' => max( 0, absint( $package['activation_limit'] ?? 0 ) ),
			);
		}

		$out[] = array(
			'id'       => absint( $update->ID ),
			'title'    => get_the_title( $update ),
			'packages' => $clean_packages,
		);
	}

	return $out;
}

/**
 * --------------------------------------------------------------------------
 * Admin AJAX
 * --------------------------------------------------------------------------
 */

add_action(
	'wp_ajax_spuupd_bootstrap',
	function () {
		check_ajax_referer( 'spuupd_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );

		if ( ! $product_id || ! class_exists( '\SmartPay\Models\Product' ) ) {
			wp_send_json_error( array( 'message' => 'SmartPay product is unavailable.' ) );
		}

		try {
			$product = \SmartPay\Models\Product::with( 'variations' )->find( $product_id );

			if ( ! $product ) {
				wp_send_json_error( array( 'message' => 'SmartPay product not found.' ) );
			}

			$variations = array();

			foreach ( $product->variations as $variation ) {
				$variations[] = array(
					'id'    => absint( $variation->id ),
					'title' => (string) $variation->title,
				);
			}

			wp_send_json_success(
				array(
					'product' => array(
						'id'         => absint( $product->id ),
						'title'      => (string) $product->title,
						'variations' => $variations,
					),
					'updates'  => spuupd_get_updates_catalogue(),
					'mappings' => spuupd_get_mappings(),
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
);

add_action(
	'wp_ajax_spuupd_save_mapping',
	function () {
		check_ajax_referer( 'spuupd_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$item_id = absint( $_POST['smartpay_item_id'] ?? 0 );

		if ( ! $item_id ) {
			wp_send_json_error( array( 'message' => 'Invalid SmartPay item.' ) );
		}

		$mapping = array(
			'update_id'        => absint( $_POST['update_id'] ?? 0 ),
			'package_id'       => sanitize_text_field( wp_unslash( $_POST['package_id'] ?? '' ) ),
			'activation_limit' => max( 0, absint( $_POST['activation_limit'] ?? 0 ) ),
		);

		spuupd_save_mapping( $item_id, $mapping );

		wp_send_json_success(
			array(
				'mapping' => spuupd_get_mapping( $item_id ),
			)
		);
	}
);

/**
 * --------------------------------------------------------------------------
 * Inline SmartPay editor mapping UI
 * --------------------------------------------------------------------------
 */

add_action(
	'admin_footer',
	function () {
		if ( ! is_admin() ) {
			return;
		}

		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( 'smartpay' !== $page ) {
			return;
		}

		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'spuupd_admin' );
		?>
		<style>
			.spuupd-map {
				margin: 14px 18px 18px;
				padding: 14px;
				border: 1px solid #dcdcde;
				border-radius: 7px;
				background: #f8f9fa;
			}
			.spuupd-map__head {
				display:flex;
				align-items:center;
				justify-content:space-between;
				gap:12px;
				margin-bottom:12px;
			}
			.spuupd-map__title {
				font-size:12px;
				font-weight:700;
				letter-spacing:.04em;
				text-transform:uppercase;
				color:#334155;
			}
			.spuupd-map__id {
				font: 11px/1.2 Consolas, Monaco, monospace;
				color:#64748b;
			}
			.spuupd-map__grid {
				display:grid;
				grid-template-columns:minmax(180px,2fr) minmax(150px,1.4fr) minmax(110px,.7fr) auto;
				gap:10px;
				align-items:end;
			}
			.spuupd-map label {
				display:block;
				margin-bottom:5px;
				font-size:11px;
				font-weight:600;
				color:#475569;
			}
			.spuupd-map select,
			.spuupd-map input[type="number"] {
				width:100%;
				min-height:34px;
			}
			.spuupd-map__save {
				min-height:34px;
				white-space:nowrap;
			}
			.spuupd-map__status {
				margin-left:8px;
				font-size:11px;
				color:#008a20;
			}
			.spuupd-map__note {
				margin:10px 0 0;
				font-size:11px;
				color:#64748b;
			}
			.spuupd-map--default {
				margin-left:0;
				margin-right:0;
			}
			@media (max-width: 1000px) {
				.spuupd-map__grid { grid-template-columns:1fr 1fr; }
			}
		</style>

		<script>
		(function () {
			'use strict';

			const ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			const nonce   = <?php echo wp_json_encode( $nonce ); ?>;

			let currentProductId = 0;
			let bootstrapData = null;
			let loading = false;
			let scheduled = false;

			function productIdFromHash() {
				const m = (location.hash || '').match(/^#\/products\/(\d+)\/edit/);
				return m ? parseInt(m[1], 10) : 0;
			}

			async function request(action, data) {
				const fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', nonce);

				Object.entries(data || {}).forEach(([key, value]) => {
					fd.append(key, String(value ?? ''));
				});

				const response = await fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: fd
				});

				return response.json();
			}

			async function load(force = false) {
				const id = productIdFromHash();

				if (!id) {
					currentProductId = 0;
					bootstrapData = null;
					removePanels();
					return;
				}

				if (!force && id === currentProductId && bootstrapData) {
					return;
				}

				if (loading) return;
				loading = true;

				try {
					const json = await request('spuupd_bootstrap', { product_id: id });

					if (!json.success) {
						console.warn('SmartPay ↔ UUPD:', json.data?.message || 'Unable to load mapping data.');
						return;
					}

					currentProductId = id;
					bootstrapData = json.data;
					removePanels();
				} finally {
					loading = false;
				}
			}

			function removePanels() {
				document.querySelectorAll('.spuupd-map').forEach(el => el.remove());
			}

			function variationCards() {
				return Array.from(document.querySelectorAll('.sp-detail-card')).filter(card => {
					const input = card.querySelector('.sp-detail-card__body input[name="title"]');
					if (!input) return false;

					return Array.from(card.querySelectorAll('.sp-detail-card__body label'))
						.some(label => label.textContent.trim().toLowerCase() === 'option name');
				});
			}

			function pricingWrapper() {
				const cards = variationCards();
				if (cards.length && cards[0].parentElement) {
					return cards[0].parentElement;
				}

				const basePrice = document.querySelector('#base_price');
				if (!basePrice) return null;

				let el = basePrice.parentElement;

				while (el && el !== document.body) {
					if (el.style && (el.style.display === 'block' || el.style.display === 'none')) {
						return el;
					}
					el = el.parentElement;
				}

				return null;
			}

			function mappingFor(itemId) {
				const all = bootstrapData?.mappings || {};
				return all[String(itemId)] || {};
			}

			function updateById(id) {
				return (bootstrapData?.updates || []).find(u => parseInt(u.id, 10) === parseInt(id, 10)) || null;
			}

			function buildPanel(itemId, label, extraClass = '') {
				const saved = mappingFor(itemId);

				const panel = document.createElement('div');
				panel.className = 'spuupd-map ' + extraClass;
				panel.dataset.spuupdItem = String(itemId);

				const head = document.createElement('div');
				head.className = 'spuupd-map__head';
				head.innerHTML =
					'<span class="spuupd-map__title">UUPD Licence Mapping — ' + escapeHtml(label) + '</span>' +
					'<span class="spuupd-map__id">SmartPay ID ' + itemId + '</span>';

				const grid = document.createElement('div');
				grid.className = 'spuupd-map__grid';

				const updateField = document.createElement('div');
				const updateLabel = document.createElement('label');
				updateLabel.textContent = 'UUPD Update';
				const updateSelect = document.createElement('select');
				updateSelect.innerHTML = '<option value="0">— No UUPD licence —</option>';

				(bootstrapData?.updates || []).forEach(update => {
					const opt = document.createElement('option');
					opt.value = update.id;
					opt.textContent = update.title + ' (#' + update.id + ')';
					if (parseInt(saved.update_id || 0, 10) === parseInt(update.id, 10)) opt.selected = true;
					updateSelect.appendChild(opt);
				});

				updateField.append(updateLabel, updateSelect);

				const packageField = document.createElement('div');
				const packageLabel = document.createElement('label');
				packageLabel.textContent = 'UUPD Package';
				const packageSelect = document.createElement('select');
				packageField.append(packageLabel, packageSelect);

				const limitField = document.createElement('div');
				const limitLabel = document.createElement('label');
				limitLabel.textContent = 'Activations';
				const limitInput = document.createElement('input');
				limitInput.type = 'number';
				limitInput.min = '0';
				limitInput.value = String(saved.activation_limit ?? 0);
				limitInput.title = '0 = package default, or unlimited when the package has no default.';
				limitField.append(limitLabel, limitInput);

				const saveWrap = document.createElement('div');
				const saveButton = document.createElement('button');
				saveButton.type = 'button';
				saveButton.className = 'button button-primary spuupd-map__save';
				saveButton.textContent = 'Save Mapping';
				const status = document.createElement('span');
				status.className = 'spuupd-map__status';
				saveWrap.append(saveButton, status);

				grid.append(updateField, packageField, limitField, saveWrap);

				const note = document.createElement('p');
				note.className = 'spuupd-map__note';
				note.textContent = 'Subscriptions automatically extend the UUPD licence on successful renewals. Cancellation keeps access until the paid-through expiry date.';

				panel.append(head, grid, note);

				function refillPackages() {
					const update = updateById(updateSelect.value);
					const previous = packageSelect.value || String(saved.package_id || '');

					packageSelect.innerHTML = '<option value="">— Default / no package —</option>';

					(update?.packages || []).forEach(pkg => {
						const opt = document.createElement('option');
						opt.value = pkg.id;
						opt.textContent = pkg.label + (pkg.activation_limit ? ' — ' + pkg.activation_limit + ' activations' : '');
						if (String(previous) === String(pkg.id)) opt.selected = true;
						opt.dataset.limit = String(pkg.activation_limit || 0);
						packageSelect.appendChild(opt);
					});
				}

				updateSelect.addEventListener('change', () => {
					refillPackages();
					const option = packageSelect.selectedOptions[0];
					if (option && parseInt(limitInput.value || '0', 10) === 0) {
						limitInput.value = option.dataset.limit || '0';
					}
				});

				packageSelect.addEventListener('change', () => {
					const option = packageSelect.selectedOptions[0];
					if (option && parseInt(limitInput.value || '0', 10) === 0) {
						limitInput.value = option.dataset.limit || '0';
					}
				});

				saveButton.addEventListener('click', async () => {
					saveButton.disabled = true;
					status.textContent = '';

					try {
						const json = await request('spuupd_save_mapping', {
							smartpay_item_id: itemId,
							update_id: updateSelect.value,
							package_id: packageSelect.value,
							activation_limit: limitInput.value
						});

						if (!json.success) {
							throw new Error(json.data?.message || 'Save failed.');
						}

						bootstrapData.mappings[String(itemId)] = json.data.mapping || {};
						if (!updateSelect.value || updateSelect.value === '0') {
							delete bootstrapData.mappings[String(itemId)];
						}

						status.textContent = 'Saved';
						setTimeout(() => { status.textContent = ''; }, 1600);
					} catch (err) {
						status.style.color = '#b32d2e';
						status.textContent = err.message || 'Error';
						setTimeout(() => {
							status.textContent = '';
							status.style.color = '';
						}, 2500);
					} finally {
						saveButton.disabled = false;
					}
				});

				refillPackages();

				return panel;
			}

			function escapeHtml(value) {
				const div = document.createElement('div');
				div.textContent = String(value ?? '');
				return div.innerHTML;
			}

			function matchVariation(card, index, used) {
				const vars = bootstrapData?.product?.variations || [];
				const input = card.querySelector('input[name="title"]');
				const title = (input?.value || '').trim().toLowerCase();

				let found = vars.find(v =>
					v.id &&
					!used.has(String(v.id)) &&
					String(v.title || '').trim().toLowerCase() === title
				);

				if (!found && vars[index] && !used.has(String(vars[index].id))) {
					found = vars[index];
				}

				return found || null;
			}

			function inject() {
				if (!bootstrapData || !currentProductId) return;

				const wrapper = pricingWrapper();
				if (!wrapper) return;

				const cards = variationCards();

				// Parent/default mapping. Variations can override it.
				if (!wrapper.querySelector(':scope > .spuupd-map--default')) {
					const parentPanel = buildPanel(
						currentProductId,
						cards.length ? 'Default / fallback product mapping' : 'Product',
						'spuupd-map--default'
					);
					wrapper.insertBefore(parentPanel, wrapper.firstChild);
				}

				const used = new Set();

				cards.forEach((card, index) => {
					if (card.querySelector(':scope > .spuupd-map')) return;

					const variation = matchVariation(card, index, used);
					if (!variation?.id) return;

					used.add(String(variation.id));

					const title = variation.title || ('Variation ' + variation.id);
					card.appendChild(buildPanel(variation.id, title));
				});
			}

			async function tick() {
				await load(false);
				inject();
			}

			const observer = new MutationObserver(() => {
				if (scheduled) return;
				scheduled = true;
				requestAnimationFrame(async () => {
					scheduled = false;
					await tick();
				});
			});

			observer.observe(document.body, { childList: true, subtree: true });

			window.addEventListener('hashchange', async () => {
				currentProductId = 0;
				bootstrapData = null;
				removePanels();
				await tick();
			});

			// After SmartPay saves a product, re-read variation IDs.
			document.addEventListener('click', event => {
				const btn = event.target.closest('button');
				if (!btn) return;

				const text = (btn.textContent || '').trim().toLowerCase();
				if (!['save', 'save changes', 'update', 'update product'].includes(text)) return;

				setTimeout(async () => {
					await load(true);
					removePanels();
					inject();
				}, 1300);
			}, true);

			tick();
		})();
		</script>
		<?php
	},
	100
);

/**
 * --------------------------------------------------------------------------
 * Payment helpers
 * --------------------------------------------------------------------------
 */

function spuupd_payment_raw_status( $payment ): string {
	if ( is_object( $payment ) && isset( $payment->attributes['status'] ) ) {
		return strtolower( (string) $payment->attributes['status'] );
	}
	return '';
}

function spuupd_payment_data( $payment ): array {
	if ( ! is_object( $payment ) ) {
		return array();
	}

	$data = $payment->data ?? array();
	return is_array( $data ) ? $data : array();
}

function spuupd_parent_payment( $payment ) {
	if ( ! is_object( $payment ) || ! class_exists( '\SmartPay\Models\Payment' ) ) {
		return null;
	}

	$parent_id = absint( $payment->parent_id ?? 0 );

	if ( $parent_id ) {
		try {
			return \SmartPay\Models\Payment::find( $parent_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	return $payment;
}

function spuupd_product_id_from_payment( $payment ): int {
	$source = spuupd_parent_payment( $payment );
	$data   = spuupd_payment_data( $source );

	return absint( $data['product_id'] ?? 0 );
}

function spuupd_billing_type_from_payment( $payment ): string {
	$source = spuupd_parent_payment( $payment );
	$data   = spuupd_payment_data( $source );

	$type = (string) ( $data['billing_type'] ?? '' );

	// Pro also stores this at the top level of the initial payment preparation,
	// but the persisted payment data contains billing_type.
	return $type;
}

function spuupd_billing_period_from_payment( $payment ): string {
	$source = spuupd_parent_payment( $payment );

	/**
	 * 1. Read the period from the actual purchased SmartPay product/variation.
	 *
	 * This is the most reliable source in the current SmartPay build. The Pro
	 * checkout sends billing_period at the top level of the prepared payment
	 * payload, while Payment::insertPayment() persists only payment_data into
	 * Payment->data. Consequently billing_period can be absent from the saved
	 * payment record even though the customer purchased a recurring product.
	 *
	 * Product/variation extra retains the configured value:
	 * Daily, Weekly, Monthly, Every 3 Months, Every 6 Months, Yearly.
	 */
	$product_id = spuupd_product_id_from_payment( $payment );

	if ( $product_id && class_exists( '\SmartPay\Models\Product' ) ) {
		try {
			$product = \SmartPay\Models\Product::find( $product_id );

			if ( $product ) {
				$extra = $product->extra ?? array();

				if ( is_string( $extra ) ) {
					$decoded = json_decode( $extra, true );
					$extra   = is_array( $decoded ) ? $decoded : maybe_unserialize( $extra );
				}

				if ( is_array( $extra ) && ! empty( $extra['billing_period'] ) ) {
					return sanitize_text_field( (string) $extra['billing_period'] );
				}
			}
		} catch ( \Throwable $e ) {
			// Continue to subscription/payment fallbacks.
		}
	}

	/**
	 * 2. Existing SmartPay Pro subscription record.
	 */
	if ( class_exists( '\SmartPayPro\Models\Subscription' ) && $source && ! empty( $source->id ) ) {
		try {
			$subscription = \SmartPayPro\Models\Subscription::where(
				'parent_payment_id',
				(int) $source->id
			)->first();

			if ( $subscription && ! empty( $subscription->period ) ) {
				return sanitize_text_field( (string) $subscription->period );
			}
		} catch ( \Throwable $e ) {
			// Continue to payment-data fallback.
		}
	}

	/**
	 * 3. Saved payment data, for compatibility with versions/installations that
	 * do persist billing_period there.
	 */
	$data = spuupd_payment_data( $source );

	if ( ! empty( $data['billing_period'] ) ) {
		return sanitize_text_field( (string) $data['billing_period'] );
	}

	return '';
}

function spuupd_is_subscription_payment( $payment ): bool {
	return 0 === strcasecmp( spuupd_billing_type_from_payment( $payment ), 'Subscription' );
}

function spuupd_user_id_from_payment( $payment ): int {
	$source = spuupd_parent_payment( $payment );

	if ( ! $source ) {
		return 0;
	}

	try {
		$customer = $source->customer ?? null;

		if ( $customer && ! empty( $customer->user_id ) ) {
			return absint( $customer->user_id );
		}
	} catch ( \Throwable $e ) {
		// Try email fallback.
	}

	$email = sanitize_email( $source->email ?? '' );

	if ( $email ) {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return absint( $user->ID );
		}
	}

	return 0;
}

function spuupd_add_period( string $date, string $period ): string {
	$tz = wp_timezone();

	try {
		$base = new DateTimeImmutable( $date ?: 'now', $tz );
	} catch ( \Throwable $e ) {
		$base = new DateTimeImmutable( 'now', $tz );
	}

	$period = strtolower( trim( $period ) );

	switch ( $period ) {
		case 'daily':
		case 'day':
			$next = $base->modify( '+1 day' );
			break;

		case 'weekly':
		case 'week':
			$next = $base->modify( '+1 week' );
			break;

		case 'every 3 months':
		case '3 months':
		case 'quarterly':
			$next = $base->modify( '+3 months' );
			break;

		case 'every 6 months':
		case '6 months':
		case 'semiannually':
		case 'semiannual':
			$next = $base->modify( '+6 months' );
			break;

		case 'yearly':
		case 'year':
		case 'annually':
		case 'annual':
			$next = $base->modify( '+1 year' );
			break;

		case 'monthly':
		case 'month':
			$next = $base->modify( '+1 month' );
			break;

		default:
			/**
			 * Do not silently invent a monthly entitlement for an unknown or
			 * missing period. This makes configuration/data issues visible.
			 */
			return '';
	}

	return $next->format( 'Y-m-d' );
}

function spuupd_subscription_expiry_for_license( int $license_id, string $period ): string {
	$today = wp_date( 'Y-m-d' );

	/**
	 * _spuupd_paid_through is the true purchased entitlement date.
	 * During a grace period the public UUPD expiry may temporarily be later,
	 * so renewals must extend from paid-through rather than from grace expiry.
	 */
	$paid_through = $license_id
		? trim( (string) get_post_meta( $license_id, '_spuupd_paid_through', true ) )
		: '';

	$current = $license_id
		? trim( (string) get_post_meta( $license_id, 'expiry', true ) )
		: '';

	$base = $today;

	if ( $paid_through && strtotime( $paid_through ) && strtotime( $paid_through ) > strtotime( $today ) ) {
		$base = $paid_through;
	} elseif ( $current && strtotime( $current ) && strtotime( $current ) > strtotime( $today ) ) {
		$base = $current;
	}

	return spuupd_add_period( $base, $period );
}

/**
 * Package default activation count is used when mapping activation_limit is 0.
 */
function spuupd_effective_activation_limit( array $mapping ): int {
	$override = max( 0, absint( $mapping['activation_limit'] ?? 0 ) );

	if ( $override > 0 ) {
		return $override;
	}

	$update_id  = absint( $mapping['update_id'] ?? 0 );
	$package_id = (string) ( $mapping['package_id'] ?? '' );

	if ( $update_id && $package_id ) {
		$packages = maybe_unserialize( get_post_meta( $update_id, 'packages', true ) );
		$packages = is_array( $packages ) ? $packages : array();

		foreach ( $packages as $package ) {
			if ( is_array( $package ) && (string) ( $package['id'] ?? '' ) === $package_id ) {
				return max( 0, absint( $package['activation_limit'] ?? 0 ) );
			}
		}
	}

	return 0;
}

/**
 * --------------------------------------------------------------------------
 * Licence lookup / association
 * --------------------------------------------------------------------------
 */

function spuupd_find_license_by_parent_payment( int $parent_payment_id, int $update_id = 0 ): int {
	$meta_query = array(
		array(
			'key'   => '_spuupd_parent_payment_id',
			'value' => (string) absint( $parent_payment_id ),
		),
	);

	if ( $update_id ) {
		$meta_query[] = array(
			'key'   => 'update_id',
			'value' => (string) absint( $update_id ),
		);
	}

	$posts = get_posts(
		array(
			'post_type'      => 'uupd_license',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => $meta_query,
		)
	);

	return empty( $posts ) ? 0 : absint( $posts[0] );
}

function spuupd_find_subscription_id( int $parent_payment_id ): int {
	if ( ! $parent_payment_id || ! class_exists( '\SmartPayPro\Models\Subscription' ) ) {
		return 0;
	}

	try {
		$subscription = \SmartPayPro\Models\Subscription::where( 'parent_payment_id', $parent_payment_id )->first();
		return $subscription ? absint( $subscription->id ) : 0;
	} catch ( \Throwable $e ) {
		return 0;
	}
}

function spuupd_associate_license( int $license_id, $payment, int $smartpay_item_id, array $mapping ): void {
	$parent = spuupd_parent_payment( $payment );
	$parent_payment_id = $parent ? absint( $parent->id ?? 0 ) : 0;

	update_post_meta( $license_id, '_spuupd_parent_payment_id', $parent_payment_id );
	update_post_meta( $license_id, '_spuupd_smartpay_item_id', $smartpay_item_id );
	update_post_meta( $license_id, '_spuupd_mapped_smartpay_id', absint( $mapping['mapped_smartpay_id'] ?? $smartpay_item_id ) );
	update_post_meta( $license_id, '_spuupd_last_payment_id', absint( $payment->id ?? 0 ) );

	$subscription_id = spuupd_find_subscription_id( $parent_payment_id );

	if ( $subscription_id ) {
		update_post_meta( $license_id, '_spuupd_subscription_id', $subscription_id );
	}
}


/**
 * --------------------------------------------------------------------------
 * Grace-period lifecycle
 * --------------------------------------------------------------------------
 */

function spuupd_clear_grace( int $license_id ): void {
	delete_post_meta( $license_id, '_spuupd_grace_started' );
	delete_post_meta( $license_id, '_spuupd_grace_until' );
	delete_post_meta( $license_id, '_spuupd_grace_mode' );
	delete_post_meta( $license_id, '_spuupd_grace_action' );
}

function spuupd_set_paid_through( int $license_id, string $date ): void {
	if ( $date ) {
		update_post_meta( $license_id, '_spuupd_paid_through', $date );
	}
}

/**
 * Apply the globally configured failed-renewal policy.
 */
function spuupd_begin_grace( int $license_id ): void {
	if ( ! $license_id || ! function_exists( 'uupd_update_license' ) ) {
		return;
	}

	$mode   = spuupd_grace_mode();
	$action = spuupd_grace_action();
	$today  = wp_date( 'Y-m-d' );

	$paid_through = trim( (string) get_post_meta( $license_id, '_spuupd_paid_through', true ) );

	if ( ! $paid_through ) {
		$paid_through = trim( (string) get_post_meta( $license_id, 'expiry', true ) );
	}

	update_post_meta( $license_id, '_spuupd_grace_started', current_time( 'mysql' ) );
	update_post_meta( $license_id, '_spuupd_grace_mode', $mode );
	update_post_meta( $license_id, '_spuupd_grace_action', $action );

	/**
	 * Immediate means no grace window.
	 */
	if ( 'immediate' === $mode ) {
		spuupd_clear_grace( $license_id );
		uupd_update_license( $license_id, array( 'status' => $action ) );
		return;
	}

	/**
	 * Keep active through the already-paid entitlement only.
	 */
	if ( 'paid_through' === $mode ) {
		if ( $paid_through ) {
			update_post_meta( $license_id, '_spuupd_grace_until', $paid_through );

			if ( strtotime( $paid_through . ' 23:59:59' ) >= current_time( 'timestamp' ) ) {
				uupd_update_license(
					$license_id,
					array(
						'status' => 'active',
						'expiry' => $paid_through,
					)
				);
				return;
			}
		}

		uupd_update_license( $license_id, array( 'status' => $action ) );
		return;
	}

	/**
	 * Fixed/custom grace is appended to the paid-through date. If the paid
	 * entitlement has already elapsed, start from today rather than backdating
	 * the grace window.
	 */
	$days = spuupd_grace_days();
	$base = $today;

	if ( $paid_through && strtotime( $paid_through ) && strtotime( $paid_through ) > strtotime( $today ) ) {
		$base = $paid_through;
	}

	try {
		$grace_until = ( new DateTimeImmutable( $base, wp_timezone() ) )
			->modify( '+' . max( 1, $days ) . ' days' )
			->format( 'Y-m-d' );
	} catch ( \Throwable $e ) {
		$grace_until = $base;
	}

	update_post_meta( $license_id, '_spuupd_grace_until', $grace_until );

	/**
	 * UUPD validates the expiry date itself, so expiry must temporarily extend
	 * to grace_until for the licence to remain genuinely usable in grace.
	 */
	uupd_update_license(
		$license_id,
		array(
			'status' => 'active',
			'expiry' => $grace_until,
		)
	);
}

function spuupd_finish_grace_if_due( int $license_id ): void {
	$grace_until = trim( (string) get_post_meta( $license_id, '_spuupd_grace_until', true ) );

	if ( ! $grace_until ) {
		return;
	}

	if ( strtotime( $grace_until . ' 23:59:59' ) >= current_time( 'timestamp' ) ) {
		return;
	}

	$action = sanitize_key( (string) get_post_meta( $license_id, '_spuupd_grace_action', true ) );
	if ( ! in_array( $action, array( 'suspended', 'expired' ), true ) ) {
		$action = spuupd_grace_action();
	}

	if ( function_exists( 'uupd_update_license' ) ) {
		uupd_update_license( $license_id, array( 'status' => $action ) );
	}

	spuupd_clear_grace( $license_id );
}

/**
 * --------------------------------------------------------------------------
 * Completed-payment processing
 * --------------------------------------------------------------------------
 */

function spuupd_process_completed_payment( $payment ): void {
	if ( ! is_object( $payment ) || empty( $payment->id ) ) {
		return;
	}

	// Payment-created renewal rows often do not contain payment->data; use parent.
	$product_id = spuupd_product_id_from_payment( $payment );

	if ( ! $product_id ) {
		return;
	}

	$mapping = spuupd_resolve_mapping( $product_id );

	if ( empty( $mapping['update_id'] ) ) {
		return;
	}

	if ( ! function_exists( 'uupd_create_license' ) || ! function_exists( 'uupd_update_license' ) ) {
		error_log( '[SmartPay ↔ UUPD] UUPD licence helpers are unavailable.' );
		return;
	}

	$parent = spuupd_parent_payment( $payment );
	$parent_payment_id = $parent ? absint( $parent->id ?? 0 ) : absint( $payment->id );
	$update_id = absint( $mapping['update_id'] );
	$is_subscription = spuupd_is_subscription_payment( $payment );
	$period = spuupd_billing_period_from_payment( $payment );
	$license_id = spuupd_find_license_by_parent_payment( $parent_payment_id, $update_id );

	// Idempotence: if this exact payment already advanced the licence, do nothing.
	if ( $license_id ) {
		$last_payment_id = absint( get_post_meta( $license_id, '_spuupd_last_payment_id', true ) );

		if ( $last_payment_id === absint( $payment->id ) ) {
			return;
		}
	}

	if ( ! $license_id ) {
		$expiry = $is_subscription ? spuupd_add_period( wp_date( 'Y-m-d' ), $period ) : '';

		if ( $is_subscription && '' === $expiry ) {
			error_log(
				sprintf(
					'[SmartPay ↔ UUPD] Could not determine billing period for payment #%d / SmartPay item #%d. Licence was not created to avoid an incorrect expiry.',
					absint( $payment->id ),
					$product_id
				)
			);
			return;
		}

		$created = uupd_create_license(
			array(
				'update_id'        => $update_id,
				'package_id'       => sanitize_text_field( $mapping['package_id'] ?? '' ),
				'user_id'          => spuupd_user_id_from_payment( $payment ),
				'activation_limit' => spuupd_effective_activation_limit( $mapping ),
				'expiry'           => $expiry,
				'order_id'         => 'smartpay:' . $parent_payment_id,
			)
		);

		if ( is_wp_error( $created ) ) {
			error_log( '[SmartPay ↔ UUPD] Licence creation failed: ' . $created->get_error_message() );
			return;
		}

		$license_id = absint( $created );

		if ( $is_subscription && $expiry ) {
			spuupd_set_paid_through( $license_id, $expiry );
		}

		spuupd_clear_grace( $license_id );
		spuupd_associate_license( $license_id, $payment, $product_id, $mapping );

		do_action( 'spuupd_license_created', $license_id, $payment, $mapping );
		return;
	}

	$updates = array(
		'status'           => 'active',
		'package_id'       => sanitize_text_field( $mapping['package_id'] ?? '' ),
		'activation_limit' => spuupd_effective_activation_limit( $mapping ),
		'user_id'          => spuupd_user_id_from_payment( $payment ),
		'order_id'         => 'smartpay:' . $parent_payment_id,
	);

	if ( $is_subscription ) {
		$renewed_expiry = spuupd_subscription_expiry_for_license( $license_id, $period );

		if ( '' === $renewed_expiry ) {
			error_log(
				sprintf(
					'[SmartPay ↔ UUPD] Could not determine renewal period for payment #%d / licence #%d. Expiry was not changed.',
					absint( $payment->id ),
					$license_id
				)
			);
			return;
		}

		$updates['expiry'] = $renewed_expiry;
	}

	$result = uupd_update_license( $license_id, $updates );

	if ( is_wp_error( $result ) ) {
		error_log( '[SmartPay ↔ UUPD] Licence renewal/update failed: ' . $result->get_error_message() );
		return;
	}

	if ( $is_subscription && ! empty( $updates['expiry'] ) ) {
		spuupd_set_paid_through( $license_id, $updates['expiry'] );
	}

	spuupd_clear_grace( $license_id );
	spuupd_associate_license( $license_id, $payment, $product_id, $mapping );

	do_action( 'spuupd_license_renewed', $license_id, $payment, $mapping );
}

/**
 * smartpay_payment_completed covers ordinary initial payments.
 */
add_action( 'smartpay_payment_completed', 'spuupd_process_completed_payment', 100, 1 );

/**
 * This status hook is important for Pro renewals. Current SmartPay Pro gateways
 * create renewal Payment rows directly with status=completed and completed_at
 * already populated, so smartpay_payment_completed may not fire for those rows.
 */
add_action(
	'smartpay_update_payment_status',
	function ( $payment, $new_status, $old_status ) {
		if ( 'completed' === strtolower( (string) $new_status ) ) {
			// Delay until the model save has assigned an ID.
			add_action(
				'shutdown',
				function () use ( $payment ) {
					if ( is_object( $payment ) && ! empty( $payment->id ) ) {
						spuupd_process_completed_payment( $payment );
					}
				},
				20
			);
		}
	},
	100,
	3
);

/**
 * --------------------------------------------------------------------------
 * Refunds / reversals
 * --------------------------------------------------------------------------
 */

function spuupd_payment_lifecycle_license( $payment ): int {
	$parent = spuupd_parent_payment( $payment );
	$parent_id = $parent ? absint( $parent->id ?? 0 ) : 0;
	$product_id = spuupd_product_id_from_payment( $payment );
	$mapping = $product_id ? spuupd_resolve_mapping( $product_id ) : array();

	return $parent_id
		? spuupd_find_license_by_parent_payment( $parent_id, absint( $mapping['update_id'] ?? 0 ) )
		: 0;
}

add_action(
	'smartpay_payment_refunded',
	function ( $payment ) {
		$license_id = spuupd_payment_lifecycle_license( $payment );

		if ( ! $license_id || ! function_exists( 'uupd_update_license' ) ) {
			return;
		}

		$is_renewal = absint( $payment->parent_id ?? 0 ) > 0;

		uupd_update_license(
			$license_id,
			array(
				'status' => $is_renewal ? 'suspended' : 'refunded',
			)
		);
	},
	100,
	1
);

add_action(
	'smartpay_payment_cancelled',
	function ( $payment ) {
		$license_id = spuupd_payment_lifecycle_license( $payment );

		if ( ! $license_id || ! function_exists( 'uupd_update_license' ) ) {
			return;
		}

		$is_renewal = absint( $payment->parent_id ?? 0 ) > 0;

		uupd_update_license(
			$license_id,
			array(
				'status' => $is_renewal ? 'suspended' : 'cancelled',
			)
		);
	},
	100,
	1
);

/**
 * --------------------------------------------------------------------------
 * SmartPay Pro subscription lifecycle
 * --------------------------------------------------------------------------
 */

add_action(
	'smartpay_update_subscription_status',
	function ( $subscription, $new_status, $old_status ) {
		if ( ! is_object( $subscription ) || ! function_exists( 'uupd_update_license' ) ) {
			return;
		}

		$parent_payment_id = absint( $subscription->parent_payment_id ?? 0 );

		if ( ! $parent_payment_id || ! class_exists( '\SmartPay\Models\Payment' ) ) {
			return;
		}

		try {
			$parent_payment = \SmartPay\Models\Payment::find( $parent_payment_id );
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! $parent_payment ) {
			return;
		}

		$product_id = spuupd_product_id_from_payment( $parent_payment );
		$mapping    = $product_id ? spuupd_resolve_mapping( $product_id ) : array();

		if ( empty( $mapping['update_id'] ) ) {
			return;
		}

		$license_id = spuupd_find_license_by_parent_payment(
			$parent_payment_id,
			absint( $mapping['update_id'] )
		);

		if ( ! $license_id ) {
			return;
		}

		$new_status = strtolower( (string) $new_status );

		update_post_meta( $license_id, '_spuupd_subscription_id', absint( $subscription->id ?? 0 ) );
		update_post_meta( $license_id, '_spuupd_subscription_status', $new_status );

		switch ( $new_status ) {
			case 'active':
			case 'trialling':
				/**
				 * Recovery clears grace state. If the failed renewal had temporarily
				 * extended the public UUPD expiry, restore the true paid-through
				 * expiry until a successful payment actually extends entitlement.
				 */
				$paid_through = trim( (string) get_post_meta( $license_id, '_spuupd_paid_through', true ) );

				spuupd_clear_grace( $license_id );

				$active_update = array( 'status' => 'active' );

				if ( $paid_through ) {
					$active_update['expiry'] = $paid_through;
				}

				uupd_update_license( $license_id, $active_update );
				break;

			case 'failing':
			case 'suspended':
				spuupd_begin_grace( $license_id );
				break;

			case 'cancelled':
				/**
				 * Cancellation means "do not renew". It is not a failed-renewal
				 * grace event. Keep access only through the purchased entitlement.
				 */
				$paid_through = trim( (string) get_post_meta( $license_id, '_spuupd_paid_through', true ) );

				if ( ! $paid_through ) {
					$paid_through = trim( (string) get_post_meta( $license_id, 'expiry', true ) );
				}

				spuupd_clear_grace( $license_id );

				if ( ! $paid_through ) {
					$period = (string) ( $subscription->period ?? spuupd_billing_period_from_payment( $parent_payment ) );
					$paid_through = spuupd_add_period( wp_date( 'Y-m-d' ), $period );
				}

				if ( $paid_through ) {
					spuupd_set_paid_through( $license_id, $paid_through );
					uupd_update_license(
						$license_id,
						array(
							'status' => 'active',
							'expiry' => $paid_through,
						)
					);
				}
				break;

			case 'expired':
			case 'completed':
				spuupd_clear_grace( $license_id );
				uupd_update_license( $license_id, array( 'status' => 'expired' ) );
				break;
		}

		do_action(
			'spuupd_subscription_status_synced',
			$license_id,
			$subscription,
			$new_status,
			$old_status
		);
	},
	100,
	3
);

/**
 * --------------------------------------------------------------------------
 * Daily expiry safety net
 * --------------------------------------------------------------------------
 */

add_action(
	'spuupd_daily_expiry_check',
	function () {
		$today = wp_date( 'Y-m-d' );

		$licenses = get_posts(
			array(
				'post_type'      => 'uupd_license',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_spuupd_parent_payment_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $licenses as $license_id ) {
			/**
			 * Grace-managed licences are finalised according to the stored action.
			 */
			$grace_until = trim( (string) get_post_meta( $license_id, '_spuupd_grace_until', true ) );

			if ( $grace_until ) {
				spuupd_finish_grace_if_due( $license_id );
				continue;
			}

			$expiry = trim( (string) get_post_meta( $license_id, 'expiry', true ) );
			$status = sanitize_key( get_post_meta( $license_id, 'status', true ) );

			if (
				$expiry &&
				$expiry < $today &&
				in_array( $status, array( 'active', 'suspended' ), true ) &&
				function_exists( 'uupd_update_license' )
			) {
				uupd_update_license( $license_id, array( 'status' => 'expired' ) );
			}
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( 'spuupd_daily_expiry_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'spuupd_daily_expiry_check' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		$timestamp = wp_next_scheduled( 'spuupd_daily_expiry_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'spuupd_daily_expiry_check' );
		}
	}
);
