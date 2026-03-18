<?php
/**
 * Frontend Dashboard
 *
 * Renders the B2B Dashboard accessible via the My Account endpoint and the
 * [b2b_dashboard] shortcode.
 *
 * Tabs:
 *   - Agent view:         Orders | Artwork Library
 *   - Company Admin view: Orders | Agents | Artwork Library | Pending Approvals
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
	 * Main render method — called by the My Account endpoint and the shortcode.
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
	// Agent Dashboard
	// -------------------------------------------------------------------------

	/**
	 * Render the agent view.
	 *
	 * @param int $user_id Agent's user ID.
	 */
	private function render_agent_dashboard( int $user_id ): void {
		$active_tab = isset( $_GET['b2b_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['b2b_tab'] ) ) : 'orders'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs       = [
			'orders'  => __( 'My Orders', 'wc-b2b-print-manager' ),
			'artwork' => __( 'Artwork Library', 'wc-b2b-print-manager' ),
		];
		?>
		<div class="b2b-dashboard b2b-dashboard--agent">
			<?php $this->render_tab_nav( $tabs, $active_tab ); ?>

			<div class="b2b-tab-content">
				<?php if ( 'orders' === $active_tab ) : ?>
					<?php $this->render_orders_tab( $user_id ); ?>
				<?php elseif ( 'artwork' === $active_tab ) : ?>
					<?php $this->render_artwork_tab( $user_id ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Company Admin Dashboard
	// -------------------------------------------------------------------------

	/**
	 * Render the company admin view.
	 *
	 * @param int $user_id Company admin's user ID.
	 */
	private function render_company_admin_dashboard( int $user_id ): void {
		$company_id = \WC_B2B\Company_Manager::get_user_company_id( $user_id );
		$active_tab = isset( $_GET['b2b_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['b2b_tab'] ) ) : 'orders'; // phpcs:ignore
		$tabs       = [
			'orders'   => __( 'Company Orders', 'wc-b2b-print-manager' ),
			'approvals' => __( 'Pending Approvals', 'wc-b2b-print-manager' ),
			'agents'   => __( 'Manage Agents', 'wc-b2b-print-manager' ),
			'artwork'  => __( 'Artwork Library', 'wc-b2b-print-manager' ),
		];
		?>
		<div class="b2b-dashboard b2b-dashboard--company-admin">
			<div class="b2b-dashboard-header">
				<?php if ( $company_id ) : ?>
					<?php $thumb = get_the_post_thumbnail( $company_id, [ 60, 60 ] ); ?>
					<?php if ( $thumb ) : ?>
						<div class="b2b-company-logo"><?php echo $thumb; ?></div>
					<?php endif; ?>
					<h2><?php echo esc_html( get_the_title( $company_id ) ); ?></h2>
				<?php endif; ?>
			</div>

			<?php $this->render_tab_nav( $tabs, $active_tab ); ?>

			<div class="b2b-tab-content">
				<?php if ( 'orders' === $active_tab ) : ?>
					<?php $this->render_company_orders_tab( $company_id ); ?>
				<?php elseif ( 'approvals' === $active_tab ) : ?>
					<?php $this->render_approvals_tab( $company_id ); ?>
				<?php elseif ( 'agents' === $active_tab ) : ?>
					<?php $this->render_agents_tab( $company_id ); ?>
				<?php elseif ( 'artwork' === $active_tab ) : ?>
					<?php $this->render_artwork_tab( $user_id, true, $company_id ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Orders (Agent)
	// -------------------------------------------------------------------------

	/**
	 * Render agent's own orders list.
	 *
	 * @param int $user_id Agent user ID.
	 */
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

	/**
	 * Render all company orders.
	 *
	 * @param int $company_id Company post ID.
	 */
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

	/**
	 * Render orders awaiting company admin approval.
	 *
	 * @param int $company_id Company post ID.
	 */
	private function render_approvals_tab( int $company_id ): void {
		$orders = \WC_B2B\Order_Controller::get_pending_orders_for_company( $company_id );
		?>
		<div class="b2b-approvals-tab">
			<?php if ( empty( $orders ) ) : ?>
				<p><?php esc_html_e( 'No orders are currently awaiting approval.', 'wc-b2b-print-manager' ); ?></p>
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
									<button class="b2b-btn b2b-btn--primary b2b-approve-order" data-order="<?php echo esc_attr( $order->get_id() ); ?>">
										<?php esc_html_e( 'Approve', 'wc-b2b-print-manager' ); ?>
									</button>
									<button class="b2b-btn b2b-btn--danger b2b-reject-order" data-order="<?php echo esc_attr( $order->get_id() ); ?>">
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
	// Tab: Agents Management
	// -------------------------------------------------------------------------

	/**
	 * Render agent management tab.
	 *
	 * @param int $company_id Company post ID.
	 */
	private function render_agents_tab( int $company_id ): void {
		$agents        = \WC_B2B\Company_Manager::get_company_users( $company_id, 'agent' );
		$manage_nonce  = wp_create_nonce( 'b2b_manage_agents' );
		?>
		<div class="b2b-agents-tab">
			<h3><?php esc_html_e( 'Company Agents', 'wc-b2b-print-manager' ); ?></h3>

			<?php if ( empty( $agents ) ) : ?>
				<p><?php esc_html_e( 'No agents are assigned to your company yet.', 'wc-b2b-print-manager' ); ?></p>
			<?php else : ?>
				<table class="b2b-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Email', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Registered', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wc-b2b-print-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $agents as $agent ) : ?>
							<tr>
								<td><?php echo esc_html( $agent->display_name ); ?></td>
								<td><?php echo esc_html( $agent->user_email ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $agent->user_registered ) ) ); ?></td>
								<td>
									<button class="b2b-btn b2b-btn--danger b2b-remove-agent"
											data-user="<?php echo esc_attr( $agent->ID ); ?>"
											data-nonce="<?php echo esc_attr( $manage_nonce ); ?>">
										<?php esc_html_e( 'Remove', 'wc-b2b-print-manager' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr />
			<h4><?php esc_html_e( 'Assign Existing User as Agent', 'wc-b2b-print-manager' ); ?></h4>
			<div class="b2b-assign-agent-form">
				<input type="text" id="b2b-agent-email"
					   placeholder="<?php esc_attr_e( 'Enter user email address…', 'wc-b2b-print-manager' ); ?>" />
				<button class="b2b-btn b2b-btn--primary" id="b2b-assign-agent"
						data-nonce="<?php echo esc_attr( $manage_nonce ); ?>">
					<?php esc_html_e( 'Assign', 'wc-b2b-print-manager' ); ?>
				</button>
				<span class="b2b-assign-agent-msg"></span>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Artwork Library
	// -------------------------------------------------------------------------

	/**
	 * Render artwork library tab.
	 *
	 * @param int  $user_id    Current user ID.
	 * @param bool $is_company Whether showing company-wide artwork.
	 * @param int  $company_id Company post ID (when $is_company is true).
	 */
	private function render_artwork_tab( int $user_id, bool $is_company = false, int $company_id = 0 ): void {
		$artwork_manager = new \WC_B2B\Artwork_Manager();
		$artwork_items   = $is_company && $company_id
			? $artwork_manager->get_company_artwork( $company_id )
			: $artwork_manager->get_user_artwork( $user_id );

		$nonce = wp_create_nonce( 'b2b_artwork_nonce' );
		?>
		<div class="b2b-artwork-tab" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<h3><?php esc_html_e( 'Artwork Library', 'wc-b2b-print-manager' ); ?></h3>

			<!-- Upload new artwork -->
			<div class="b2b-artwork-upload-section">
				<h4><?php esc_html_e( 'Upload New Artwork', 'wc-b2b-print-manager' ); ?></h4>
				<input type="text" id="b2b-artwork-title"
					   placeholder="<?php esc_attr_e( 'Artwork title…', 'wc-b2b-print-manager' ); ?>" />
				<input type="file" id="b2b-artwork-upload-file"
					   accept=".pdf,.ai,.eps,.jpg,.jpeg,.png,.tiff,.tif" multiple />
				<button class="b2b-btn b2b-btn--primary" id="b2b-upload-artwork">
					<?php esc_html_e( 'Upload', 'wc-b2b-print-manager' ); ?>
				</button>
				<span class="b2b-upload-status"></span>
			</div>

			<!-- Existing artwork -->
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
	 * Render tab navigation.
	 *
	 * @param array  $tabs       ['slug' => 'Label'] pairs.
	 * @param string $active_tab Currently active tab slug.
	 */
	private function render_tab_nav( array $tabs, string $active_tab ): void {
		$base_url = remove_query_arg( 'b2b_tab' );
		?>
		<nav class="b2b-tabs">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'b2b_tab', $slug, $base_url ) ); ?>"
				   class="b2b-tab <?php echo $active_tab === $slug ? 'b2b-tab--active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
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
				__( 'Please <a href="%s">log in</a> to access the B2B Dashboard.', 'wc-b2b-print-manager' ),
				esc_url( wp_login_url( get_permalink() ) )
			)
		) . '</p>';
	}
}
