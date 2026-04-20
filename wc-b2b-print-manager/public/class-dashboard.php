<?php
/**
 * Frontend Dashboard
 *
 * Renders the branded Company Portal accessible via:
 *   - /my-account/b2b-dashboard/
 *   - [b2b_dashboard] shortcode
 *
 * Tab structure
 * ─────────────
 * Company Admin:  Overview | Company Orders | Pending Approvals | Team | Artwork
 * Agent:          Overview | My Orders      | Artwork
 *
 * The Overview tab is a full company portal home:
 *   - Company logo + name banner
 *   - "Hello, [name]" greeting
 *   - Stats cards (role-specific)
 *   - Quick-action buttons
 *
 * @package WC_B2B\Frontend
 */

declare( strict_types=1 );

namespace WC_B2B\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Dashboard
 */
class Dashboard {

	/**
	 * Register the shortcode on construction.
	 */
	public function __construct() {
		add_shortcode( 'b2b_dashboard', [ $this, 'shortcode' ] );
	}

	// -------------------------------------------------------------------------
	// Entry points
	// -------------------------------------------------------------------------

	/**
	 * Shortcode callback: [b2b_dashboard].
	 *
	 * @return string HTML output.
	 */
	public function shortcode(): string {
		ob_start();
		$this->render();
		return ob_get_clean() ?: '';
	}

	/**
	 * Main render method.
	 */
	public function render(): void {
		if ( ! is_user_logged_in() ) {
			$this->render_login_prompt();
			return;
		}

		$user_id = get_current_user_id();

		if ( \WC_B2B\Role_Manager::is_company_admin( $user_id ) ) {
			$this->render_company_admin_dashboard( $user_id );
		} elseif ( \WC_B2B\Role_Manager::is_agent( $user_id ) ) {
			$this->render_agent_dashboard( $user_id );
		} else {
			echo '<p>' . esc_html__( 'You do not have access to the B2B Dashboard.', 'wc-b2b-print-manager' ) . '</p>';
		}
	}

	// -------------------------------------------------------------------------
	// Company Admin Dashboard
	// -------------------------------------------------------------------------

	/**
	 * @param int $user_id Company admin user ID.
	 */
	private function render_company_admin_dashboard( int $user_id ): void {
		$company_id = \WC_B2B\Company_Manager::get_user_company_id( $user_id );
		$active_tab = isset( $_GET['b2b_tab'] ) // phpcs:ignore WordPress.Security.NonceVerification
			? sanitize_text_field( wp_unslash( $_GET['b2b_tab'] ) ) // phpcs:ignore
			: 'overview';

		$tabs = [
			'overview'  => __( 'Overview', 'wc-b2b-print-manager' ),
			'orders'    => __( 'Company Orders', 'wc-b2b-print-manager' ),
			'approvals' => __( 'Pending Approvals', 'wc-b2b-print-manager' ),
			'team'      => __( 'Team', 'wc-b2b-print-manager' ),
			'artwork'   => __( 'Artwork Library', 'wc-b2b-print-manager' ),
		];

		// Count pending approvals for the badge.
		$pending_count = $company_id
			? count( \WC_B2B\Order_Controller::get_pending_orders_for_company( $company_id ) )
			: 0;
		if ( $pending_count > 0 ) {
			$tabs['approvals'] .= ' <span class="b2b-tab-badge">' . $pending_count . '</span>';
		}
		?>
		<div class="b2b-dashboard b2b-dashboard--company-admin">

			<?php $this->render_company_banner( $company_id, $user_id ); ?>
			<?php $this->render_tab_nav( $tabs, $active_tab ); ?>

			<div class="b2b-tab-content">
				<?php if ( 'overview' === $active_tab ) : ?>
					<?php $this->render_overview_tab_admin( $user_id, $company_id ); ?>
				<?php elseif ( 'orders' === $active_tab ) : ?>
					<?php $this->render_company_orders_tab( $company_id ); ?>
				<?php elseif ( 'approvals' === $active_tab ) : ?>
					<?php $this->render_approvals_tab( $company_id ); ?>
				<?php elseif ( 'team' === $active_tab ) : ?>
					<?php $this->render_team_tab( $company_id ); ?>
				<?php elseif ( 'artwork' === $active_tab ) : ?>
					<?php $this->render_artwork_tab( $user_id, true, $company_id ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Agent Dashboard
	// -------------------------------------------------------------------------

	/**
	 * @param int $user_id Agent user ID.
	 */
	private function render_agent_dashboard( int $user_id ): void {
		$company_id = \WC_B2B\Company_Manager::get_user_company_id( $user_id );
		$active_tab = isset( $_GET['b2b_tab'] ) // phpcs:ignore WordPress.Security.NonceVerification
			? sanitize_text_field( wp_unslash( $_GET['b2b_tab'] ) ) // phpcs:ignore
			: 'overview';

		$tabs = [
			'overview' => __( 'Overview', 'wc-b2b-print-manager' ),
			'orders'   => __( 'My Orders', 'wc-b2b-print-manager' ),
			'artwork'  => __( 'Artwork Library', 'wc-b2b-print-manager' ),
		];
		?>
		<div class="b2b-dashboard b2b-dashboard--agent">

			<?php $this->render_company_banner( $company_id, $user_id ); ?>
			<?php $this->render_tab_nav( $tabs, $active_tab ); ?>

			<div class="b2b-tab-content">
				<?php if ( 'overview' === $active_tab ) : ?>
					<?php $this->render_overview_tab_agent( $user_id, $company_id ); ?>
				<?php elseif ( 'orders' === $active_tab ) : ?>
					<?php $this->render_orders_tab( $user_id ); ?>
				<?php elseif ( 'artwork' === $active_tab ) : ?>
					<?php $this->render_artwork_tab( $user_id ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Company Banner (shared)
	// -------------------------------------------------------------------------

	/**
	 * Render the top company branding banner shown on all dashboard views.
	 *
	 * @param int $company_id Company post ID (0 if not assigned).
	 * @param int $user_id    Current user ID.
	 */
	private function render_company_banner( int $company_id, int $user_id ): void {
		$user         = get_user_by( 'id', $user_id );
		$company      = $company_id ? \WC_B2B\Company_Manager::get_company( $company_id ) : null;
		$company_name = $company ? $company->post_title : __( 'Company Portal', 'wc-b2b-print-manager' );
		$logo_id      = $company_id ? get_post_thumbnail_id( $company_id ) : 0;
		$logo_url     = $logo_id ? wp_get_attachment_image_url( $logo_id, [ 120, 120 ] ) : '';
		$role_label   = \WC_B2B\Role_Manager::get_role_label( $user_id );
		?>
		<div class="b2b-company-banner">
			<div class="b2b-company-banner__logo">
				<?php if ( $logo_url ) : ?>
					<img src="<?php echo esc_url( $logo_url ); ?>"
						 alt="<?php echo esc_attr( $company_name ); ?>"
						 class="b2b-company-logo-img" />
				<?php else : ?>
					<div class="b2b-company-logo-placeholder">
						<?php echo esc_html( mb_substr( $company_name, 0, 2 ) ); ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="b2b-company-banner__info">
				<h1 class="b2b-company-name"><?php echo esc_html( $company_name ); ?></h1>
				<p class="b2b-banner-greeting">
					<?php
					printf(
						/* translators: 1: user display name, 2: role label */
						esc_html__( 'Welcome back, %1$s — %2$s', 'wc-b2b-print-manager' ),
						'<strong>' . esc_html( $user ? $user->display_name : '' ) . '</strong>',
						'<span class="b2b-role-chip">' . esc_html( $role_label ) . '</span>'
					);
					?>
				</p>
			</div>
			<div class="b2b-company-banner__actions">
				<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>"
				   class="b2b-btn b2b-btn--primary">
					<?php esc_html_e( '+ New Order', 'wc-b2b-print-manager' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Overview — Company Admin
	// -------------------------------------------------------------------------

	/**
	 * @param int $user_id    Company admin user ID.
	 * @param int $company_id Company post ID.
	 */
	private function render_overview_tab_admin( int $user_id, int $company_id ): void {
		// Gather stats.
		$users         = $company_id ? \WC_B2B\Company_Manager::get_company_users( $company_id ) : [];
		$user_ids      = array_map( fn( $u ) => $u->ID, $users );
		$all_orders    = empty( $user_ids ) ? [] : wc_get_orders( [ 'customer' => $user_ids, 'limit' => -1 ] );
		$pending       = $company_id ? \WC_B2B\Order_Controller::get_pending_orders_for_company( $company_id ) : [];
		$agents        = array_filter( $users, fn( $u ) => \WC_B2B\Role_Manager::is_agent( $u->ID ) );

		// Most recent order.
		$latest_order  = ! empty( $all_orders ) ? $all_orders[0] : null;

		$stats = [
			[
				'icon'  => '📦',
				'value' => count( $all_orders ),
				'label' => __( 'Total Orders', 'wc-b2b-print-manager' ),
				'link'  => add_query_arg( 'b2b_tab', 'orders', remove_query_arg( 'b2b_tab' ) ),
			],
			[
				'icon'  => '⏳',
				'value' => count( $pending ),
				'label' => __( 'Pending Approval', 'wc-b2b-print-manager' ),
				'link'  => add_query_arg( 'b2b_tab', 'approvals', remove_query_arg( 'b2b_tab' ) ),
				'alert' => count( $pending ) > 0,
			],
			[
				'icon'  => '👥',
				'value' => count( $agents ),
				'label' => __( 'Agents', 'wc-b2b-print-manager' ),
				'link'  => add_query_arg( 'b2b_tab', 'team', remove_query_arg( 'b2b_tab' ) ),
			],
			[
				'icon'  => '🖼️',
				'value' => count( ( new \WC_B2B\Artwork_Manager() )->get_company_artwork( $company_id ) ),
				'label' => __( 'Artwork Files', 'wc-b2b-print-manager' ),
				'link'  => add_query_arg( 'b2b_tab', 'artwork', remove_query_arg( 'b2b_tab' ) ),
			],
		];

		$this->render_stats_grid( $stats );

		// Recent activity.
		if ( ! empty( $all_orders ) ) {
			$recent = array_slice( $all_orders, 0, 5 );
			?>
			<div class="b2b-overview-section">
				<h3><?php esc_html_e( 'Recent Orders', 'wc-b2b-print-manager' ); ?></h3>
				<?php
				$orders            = $recent;
				$show_agent_column = true;
				include WC_B2B_PLUGIN_DIR . 'public/views/order-list.php';
				?>
			</div>
			<?php
		}

		// Pending approvals callout.
		if ( count( $pending ) > 0 ) {
			?>
			<div class="b2b-overview-callout b2b-overview-callout--warning">
				<strong>
					<?php
					printf(
						/* translators: %d: count */
						esc_html( _n(
							'%d order is awaiting your approval.',
							'%d orders are awaiting your approval.',
							count( $pending ),
							'wc-b2b-print-manager'
						) ),
						count( $pending )
					);
					?>
				</strong>
				<a href="<?php echo esc_url( add_query_arg( 'b2b_tab', 'approvals', remove_query_arg( 'b2b_tab' ) ) ); ?>"
				   class="b2b-btn b2b-btn--sm">
					<?php esc_html_e( 'Review Now', 'wc-b2b-print-manager' ); ?>
				</a>
			</div>
			<?php
		}
	}

	// -------------------------------------------------------------------------
	// Tab: Overview — Agent
	// -------------------------------------------------------------------------

	/**
	 * @param int $user_id    Agent user ID.
	 * @param int $company_id Company post ID.
	 */
	private function render_overview_tab_agent( int $user_id, int $company_id ): void {
		$all_orders = wc_get_orders( [ 'customer' => $user_id, 'limit' => -1 ] );
		$processing = wc_get_orders( [ 'customer' => $user_id, 'status' => 'processing', 'limit' => -1 ] );
		$pending_ap = wc_get_orders( [ 'customer' => $user_id, 'status' => 'wc-pending-approval', 'limit' => -1 ] );

		$latest_order = ! empty( $all_orders ) ? $all_orders[0] : null;

		$stats = [
			[
				'icon'  => '📦',
				'value' => count( $all_orders ),
				'label' => __( 'My Orders', 'wc-b2b-print-manager' ),
				'link'  => add_query_arg( 'b2b_tab', 'orders', remove_query_arg( 'b2b_tab' ) ),
			],
			[
				'icon'  => '✅',
				'value' => count( $processing ),
				'label' => __( 'In Processing', 'wc-b2b-print-manager' ),
				'link'  => add_query_arg( 'b2b_tab', 'orders', remove_query_arg( 'b2b_tab' ) ),
			],
			[
				'icon'  => '⏳',
				'value' => count( $pending_ap ),
				'label' => __( 'Pending Approval', 'wc-b2b-print-manager' ),
				'link'  => add_query_arg( 'b2b_tab', 'orders', remove_query_arg( 'b2b_tab' ) ),
				'alert' => count( $pending_ap ) > 0,
			],
			[
				'icon'  => '🗓️',
				'value' => $latest_order
					? wc_format_datetime( $latest_order->get_date_created(), get_option( 'date_format' ) )
					: '—',
				'label' => __( 'Last Order', 'wc-b2b-print-manager' ),
				'link'  => $latest_order ? $latest_order->get_view_order_url() : '#',
			],
		];

		$this->render_stats_grid( $stats );

		// Recent orders snippet.
		if ( ! empty( $all_orders ) ) {
			$recent = array_slice( $all_orders, 0, 5 );
			?>
			<div class="b2b-overview-section">
				<h3><?php esc_html_e( 'Recent Orders', 'wc-b2b-print-manager' ); ?></h3>
				<?php
				$orders = $recent;
				include WC_B2B_PLUGIN_DIR . 'public/views/order-list.php';
				?>
			</div>
			<?php
		} else {
			?>
			<div class="b2b-overview-empty">
				<p><?php esc_html_e( 'You haven\'t placed any orders yet.', 'wc-b2b-print-manager' ); ?></p>
				<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>"
				   class="b2b-btn b2b-btn--primary">
					<?php esc_html_e( 'Browse Products', 'wc-b2b-print-manager' ); ?>
				</a>
			</div>
			<?php
		}
	}

	// -------------------------------------------------------------------------
	// Stats grid helper
	// -------------------------------------------------------------------------

	/**
	 * Render a row of KPI stat cards.
	 *
	 * @param array $stats Each item: [icon, value, label, link, alert?]
	 */
	private function render_stats_grid( array $stats ): void {
		?>
		<div class="b2b-stats-grid">
			<?php foreach ( $stats as $stat ) : ?>
				<a href="<?php echo esc_url( $stat['link'] ?? '#' ); ?>"
				   class="b2b-stat-card <?php echo ! empty( $stat['alert'] ) ? 'b2b-stat-card--alert' : ''; ?>">
					<span class="b2b-stat-icon"><?php echo esc_html( $stat['icon'] ); ?></span>
					<span class="b2b-stat-value"><?php echo esc_html( (string) $stat['value'] ); ?></span>
					<span class="b2b-stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Orders (Agent)
	// -------------------------------------------------------------------------

	private function render_orders_tab( int $user_id ): void {
		$orders = wc_get_orders(
			[
				'customer' => $user_id,
				'limit'    => 20,
				'orderby'  => 'date',
				'order'    => 'DESC',
			]
		);
		include WC_B2B_PLUGIN_DIR . 'public/views/order-list.php';
	}

	// -------------------------------------------------------------------------
	// Tab: Orders (Company Admin)
	// -------------------------------------------------------------------------

	private function render_company_orders_tab( int $company_id ): void {
		$users    = \WC_B2B\Company_Manager::get_company_users( $company_id );
		$user_ids = array_map( fn( $u ) => $u->ID, $users );
		$orders   = empty( $user_ids ) ? [] : wc_get_orders(
			[
				'customer' => $user_ids,
				'limit'    => 50,
				'orderby'  => 'date',
				'order'    => 'DESC',
			]
		);

		$show_agent_column = true;
		include WC_B2B_PLUGIN_DIR . 'public/views/order-list.php';
	}

	// -------------------------------------------------------------------------
	// Tab: Pending Approvals
	// -------------------------------------------------------------------------

	private function render_approvals_tab( int $company_id ): void {
		$orders = \WC_B2B\Order_Controller::get_pending_orders_for_company( $company_id );
		?>
		<div class="b2b-approvals-tab">
			<?php if ( empty( $orders ) ) : ?>
				<div class="b2b-overview-empty">
					<p><?php esc_html_e( 'No orders are currently awaiting approval. 🎉', 'wc-b2b-print-manager' ); ?></p>
				</div>
			<?php else : ?>
				<table class="b2b-table b2b-approvals-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Date', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Agent', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Total', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wc-b2b-print-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orders as $order ) : /** @var \WC_Order $order */ ?>
							<?php
							$agent_id = (int) $order->get_meta( '_b2b_agent_id' );
							$agent    = $agent_id ? get_user_by( 'id', $agent_id ) : null;
							?>
							<tr data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
								<td><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
								<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
								<td><?php echo $agent ? esc_html( $agent->display_name ) : '—'; ?></td>
								<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
								<td>
									<button class="b2b-btn b2b-btn--primary b2b-btn--sm b2b-approve-order"
											data-order="<?php echo esc_attr( $order->get_id() ); ?>">
										<?php esc_html_e( 'Approve', 'wc-b2b-print-manager' ); ?>
									</button>
									<button class="b2b-btn b2b-btn--danger b2b-btn--sm b2b-reject-order"
											data-order="<?php echo esc_attr( $order->get_id() ); ?>">
										<?php esc_html_e( 'Reject', 'wc-b2b-print-manager' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Team
	// -------------------------------------------------------------------------

	private function render_team_tab( int $company_id ): void {
		$members   = \WC_B2B\Company_Manager::get_company_users( $company_id );
		$nonce     = wp_create_nonce( 'b2b_team_management' );
		$ajax_url  = admin_url( 'admin-ajax.php' );
		?>
		<div class="b2b-team-tab" data-company="<?php echo esc_attr( $company_id ); ?>">

			<h3><?php esc_html_e( 'Team Members', 'wc-b2b-print-manager' ); ?></h3>

			<?php if ( empty( $members ) ) : ?>
				<p class="b2b-no-data b2b-team-empty"><?php esc_html_e( 'No team members yet.', 'wc-b2b-print-manager' ); ?></p>
			<?php else : ?>
				<table class="b2b-table b2b-team-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Email', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Role', 'wc-b2b-print-manager' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="b2b-team-tbody">
						<?php foreach ( $members as $member ) : ?>
							<tr data-user-id="<?php echo esc_attr( $member->ID ); ?>">
								<td><?php echo esc_html( $member->display_name ); ?></td>
								<td><?php echo esc_html( $member->user_email ); ?></td>
								<td><span class="b2b-role-chip"><?php echo esc_html( \WC_B2B\Role_Manager::get_role_label( $member->ID ) ); ?></span></td>
								<td>
									<button type="button" class="b2b-btn b2b-btn--sm b2b-btn--danger b2b-remove-team-member"
											data-user="<?php echo esc_attr( $member->ID ); ?>">
										<?php esc_html_e( 'Remove', 'wc-b2b-print-manager' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div class="b2b-team-forms">

				<div class="b2b-team-form-card">
					<h4><?php esc_html_e( 'Add Existing Employee', 'wc-b2b-print-manager' ); ?></h4>
					<p class="b2b-form-desc"><?php esc_html_e( 'Enter the email of an existing account to add them to your team.', 'wc-b2b-print-manager' ); ?></p>
					<div class="b2b-form-row">
						<label for="b2b-add-emp-email"><?php esc_html_e( 'Email', 'wc-b2b-print-manager' ); ?></label>
						<input type="email" id="b2b-add-emp-email" class="b2b-input"
							   placeholder="<?php esc_attr_e( 'user@example.com', 'wc-b2b-print-manager' ); ?>" />
					</div>
					<div class="b2b-form-row">
						<label for="b2b-add-emp-role"><?php esc_html_e( 'Role', 'wc-b2b-print-manager' ); ?></label>
						<select id="b2b-add-emp-role" class="b2b-select">
							<option value="agent"><?php esc_html_e( 'Agent', 'wc-b2b-print-manager' ); ?></option>
							<option value="company_admin"><?php esc_html_e( 'Company Admin', 'wc-b2b-print-manager' ); ?></option>
						</select>
					</div>
					<button type="button" class="b2b-btn b2b-btn--primary" id="b2b-add-emp-btn">
						<?php esc_html_e( 'Add to Team', 'wc-b2b-print-manager' ); ?>
					</button>
					<span class="b2b-form-msg" id="b2b-add-emp-msg"></span>
				</div>

				<div class="b2b-team-form-card">
					<h4><?php esc_html_e( 'Create New Employee', 'wc-b2b-print-manager' ); ?></h4>
					<p class="b2b-form-desc"><?php esc_html_e( 'Create a new account and immediately add them to your team.', 'wc-b2b-print-manager' ); ?></p>
					<div class="b2b-form-row">
						<label for="b2b-new-emp-first"><?php esc_html_e( 'First Name', 'wc-b2b-print-manager' ); ?></label>
						<input type="text" id="b2b-new-emp-first" class="b2b-input" />
					</div>
					<div class="b2b-form-row">
						<label for="b2b-new-emp-last"><?php esc_html_e( 'Last Name', 'wc-b2b-print-manager' ); ?></label>
						<input type="text" id="b2b-new-emp-last" class="b2b-input" />
					</div>
					<div class="b2b-form-row">
						<label for="b2b-new-emp-email"><?php esc_html_e( 'Email', 'wc-b2b-print-manager' ); ?></label>
						<input type="email" id="b2b-new-emp-email" class="b2b-input"
							   placeholder="<?php esc_attr_e( 'user@example.com', 'wc-b2b-print-manager' ); ?>" />
					</div>
					<div class="b2b-form-row">
						<label for="b2b-new-emp-role"><?php esc_html_e( 'Role', 'wc-b2b-print-manager' ); ?></label>
						<select id="b2b-new-emp-role" class="b2b-select">
							<option value="agent"><?php esc_html_e( 'Agent', 'wc-b2b-print-manager' ); ?></option>
							<option value="company_admin"><?php esc_html_e( 'Company Admin', 'wc-b2b-print-manager' ); ?></option>
						</select>
					</div>
					<div class="b2b-form-row">
						<label>
							<input type="checkbox" id="b2b-new-emp-send-pass" value="1" checked />
							<?php esc_html_e( 'Email login credentials to new employee', 'wc-b2b-print-manager' ); ?>
						</label>
					</div>
					<button type="button" class="b2b-btn b2b-btn--primary" id="b2b-create-emp-btn">
						<?php esc_html_e( 'Create &amp; Add to Team', 'wc-b2b-print-manager' ); ?>
					</button>
					<span class="b2b-form-msg" id="b2b-create-emp-msg"></span>
				</div>

			</div><!-- .b2b-team-forms -->
		</div><!-- .b2b-team-tab -->

		<script>
		(function() {
			var ajaxUrl  = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;

			function teamMsg( elId, msg, isErr ) {
				var el = document.getElementById( elId );
				if ( ! el ) return;
				el.textContent = msg;
				el.style.color = isErr ? '#ef4444' : '#059669';
				if ( ! isErr ) setTimeout( function() { el.textContent = ''; }, 4000 );
			}

			function postAjax( data, done ) {
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', ajaxUrl );
				xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
				xhr.onload = function() {
					try {
						var r = JSON.parse( xhr.responseText );
						done( r.success, r.success ? r.data : r.data );
					} catch(e) {
						done( false, { message: 'Invalid server response.' } );
					}
				};
				xhr.onerror = function() { done( false, { message: 'Network error.' } ); };
				var pairs = [];
				for ( var k in data ) {
					if ( data.hasOwnProperty(k) ) {
						pairs.push( encodeURIComponent(k) + '=' + encodeURIComponent(data[k]) );
					}
				}
				xhr.send( pairs.join('&') );
			}

			function appendTeamRow( member ) {
				var tbody = document.getElementById('b2b-team-tbody');
				if ( ! tbody ) {
					// Build table from scratch if only the empty placeholder exists.
					var placeholder = document.querySelector('.b2b-team-empty');
					var table = document.createElement('table');
					table.className = 'b2b-table b2b-team-table';
					table.innerHTML = '<thead><tr><th>Name</th><th>Email</th><th>Role</th><th></th></tr></thead><tbody id="b2b-team-tbody"></tbody>';
					if ( placeholder ) { placeholder.parentNode.replaceChild( table, placeholder ); }
					tbody = document.getElementById('b2b-team-tbody');
				}
				// Remove duplicate if re-adding same user.
				var existing = tbody.querySelector('[data-user-id="' + member.user_id + '"]');
				if ( existing ) existing.parentNode.removeChild( existing );

				var tr = document.createElement('tr');
				tr.setAttribute('data-user-id', member.user_id);
				tr.innerHTML =
					'<td>' + escHtml(member.display_name) + '</td>' +
					'<td>' + escHtml(member.email) + '</td>' +
					'<td><span class="b2b-role-chip">' + escHtml(member.role_label) + '</span></td>' +
					'<td><button type="button" class="b2b-btn b2b-btn--sm b2b-btn--danger b2b-remove-team-member" data-user="' + member.user_id + '">Remove</button></td>';
				tbody.appendChild(tr);
			}

			function escHtml( str ) {
				return String(str)
					.replace(/&/g,'&amp;').replace(/</g,'&lt;')
					.replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

			// Add existing employee.
			var addBtn = document.getElementById('b2b-add-emp-btn');
			if ( addBtn ) {
				addBtn.addEventListener('click', function() {
					var email = document.getElementById('b2b-add-emp-email').value.trim();
					var role  = document.getElementById('b2b-add-emp-role').value;
					if ( ! email ) { teamMsg('b2b-add-emp-msg', 'Please enter an email address.', true); return; }
					addBtn.disabled = true;
					postAjax({ action:'b2b_frontend_add_employee', email:email, role:role, _nonce:nonce }, function(ok, data) {
						if ( ok ) { appendTeamRow(data); document.getElementById('b2b-add-emp-email').value=''; teamMsg('b2b-add-emp-msg', data.message, false); }
						else { teamMsg('b2b-add-emp-msg', data.message||'Error.', true); }
						addBtn.disabled = false;
					});
				});
			}

			// Create new employee.
			var createBtn = document.getElementById('b2b-create-emp-btn');
			if ( createBtn ) {
				createBtn.addEventListener('click', function() {
					var email = document.getElementById('b2b-new-emp-email').value.trim();
					if ( ! email ) { teamMsg('b2b-create-emp-msg', 'Email address is required.', true); return; }
					createBtn.disabled = true;
					var sendPass = document.getElementById('b2b-new-emp-send-pass').checked ? '1' : '';
					postAjax({
						action:        'b2b_frontend_create_employee',
						first_name:    document.getElementById('b2b-new-emp-first').value.trim(),
						last_name:     document.getElementById('b2b-new-emp-last').value.trim(),
						email:         email,
						role:          document.getElementById('b2b-new-emp-role').value,
						send_password: sendPass,
						_nonce:        nonce,
					}, function(ok, data) {
						if ( ok ) {
							appendTeamRow(data);
							document.getElementById('b2b-new-emp-first').value='';
							document.getElementById('b2b-new-emp-last').value='';
							document.getElementById('b2b-new-emp-email').value='';
							teamMsg('b2b-create-emp-msg', data.message, false);
						} else {
							teamMsg('b2b-create-emp-msg', data.message||'Error.', true);
						}
						createBtn.disabled = false;
					});
				});
			}

			// Remove employee — delegated on tbody.
			document.addEventListener('click', function(e) {
				var btn = e.target.closest('.b2b-remove-team-member');
				if ( ! btn ) return;
				if ( ! window.confirm('Remove this employee from your team?') ) return;
				var userId = btn.getAttribute('data-user');
				btn.disabled = true;
				postAjax({ action:'b2b_frontend_remove_employee', user_id:userId, _nonce:nonce }, function(ok, data) {
					if ( ok ) {
						var row = btn.closest('tr');
						if ( row ) row.parentNode.removeChild(row);
					} else {
						alert( data.message || 'Error.' );
						btn.disabled = false;
					}
				});
			});
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Artwork Library
	// -------------------------------------------------------------------------

	private function render_artwork_tab( int $user_id, bool $is_company = false, int $company_id = 0 ): void {
		$artwork_manager = new \WC_B2B\Artwork_Manager();
		$artwork_items   = $is_company && $company_id
			? $artwork_manager->get_company_artwork( $company_id )
			: $artwork_manager->get_user_artwork( $user_id );

		$nonce = wp_create_nonce( 'b2b_artwork_nonce' );
		?>
		<div class="b2b-artwork-tab" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<h3><?php esc_html_e( 'Artwork Library', 'wc-b2b-print-manager' ); ?></h3>

			<div class="b2b-artwork-upload-section">
				<input type="text" id="b2b-artwork-title"
					   placeholder="<?php esc_attr_e( 'Artwork title…', 'wc-b2b-print-manager' ); ?>" />
				<input type="file" id="b2b-artwork-upload-file"
					   accept=".pdf,.ai,.eps,.jpg,.jpeg,.png,.tiff,.tif" multiple />
				<button class="b2b-btn b2b-btn--primary" id="b2b-upload-artwork">
					<?php esc_html_e( 'Upload', 'wc-b2b-print-manager' ); ?>
				</button>
				<span class="b2b-upload-status"></span>
			</div>

			<div class="b2b-artwork-grid" id="b2b-artwork-grid">
				<?php if ( empty( $artwork_items ) ) : ?>
					<p class="b2b-no-data"><?php esc_html_e( 'No artwork in your library yet.', 'wc-b2b-print-manager' ); ?></p>
				<?php else : ?>
					<?php foreach ( $artwork_items as $item ) : ?>
						<div class="b2b-artwork-item" data-id="<?php echo esc_attr( $item->id ); ?>">
							<div class="b2b-artwork-preview">
								<?php if ( in_array( strtolower( pathinfo( $item->file_url, PATHINFO_EXTENSION ) ), [ 'jpg', 'jpeg', 'png' ], true ) ) : ?>
									<img src="<?php echo esc_url( $item->file_url ); ?>"
										 alt="<?php echo esc_attr( $item->title ); ?>" loading="lazy" />
								<?php else : ?>
									<span class="b2b-artwork-icon dashicons dashicons-media-document"></span>
								<?php endif; ?>
							</div>
							<div class="b2b-artwork-info">
								<strong><?php echo esc_html( $item->title ); ?></strong>
								<?php if ( isset( $item->owner_name ) ) : ?>
									<small><?php echo esc_html__( 'By:', 'wc-b2b-print-manager' ) . ' ' . esc_html( $item->owner_name ); ?></small>
								<?php endif; ?>
								<span class="b2b-artwork-type"><?php echo esc_html( strtoupper( pathinfo( $item->file_url, PATHINFO_EXTENSION ) ) ); ?></span>
							</div>
							<div class="b2b-artwork-actions">
								<a href="<?php echo esc_url( $item->file_url ); ?>" class="b2b-btn b2b-btn--sm" target="_blank" download>
									<?php esc_html_e( 'Download', 'wc-b2b-print-manager' ); ?>
								</a>
								<button class="b2b-btn b2b-btn--sm b2b-btn--danger b2b-delete-artwork"
										data-id="<?php echo esc_attr( $item->id ); ?>">
									<?php esc_html_e( 'Delete', 'wc-b2b-print-manager' ); ?>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Render tab navigation bar.
	 *
	 * @param array  $tabs       ['slug' => 'Label'] — label may contain HTML (badge).
	 * @param string $active_tab Currently active slug.
	 */
	private function render_tab_nav( array $tabs, string $active_tab ): void {
		$base_url = remove_query_arg( 'b2b_tab' );
		?>
		<nav class="b2b-tabs" role="tablist">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'b2b_tab', $slug, $base_url ) ); ?>"
				   class="b2b-tab <?php echo $active_tab === $slug ? 'b2b-tab--active' : ''; ?>"
				   role="tab">
					<?php echo wp_kses( $label, [ 'span' => [ 'class' => [] ] ] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render a "please log in" prompt.
	 */
	private function render_login_prompt(): void {
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: login URL */
				__( 'Please <a href="%s">log in</a> to access the Company Portal.', 'wc-b2b-print-manager' ),
				esc_url( wp_login_url( get_permalink() ) )
			)
		) . '</p>';
	}
}
