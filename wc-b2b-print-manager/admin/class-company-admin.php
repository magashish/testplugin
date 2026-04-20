<?php
/**
 * Company Admin
 *
 * Admin-side company management:
 *   - Company list table columns (logo thumbnail, member count, pricing type).
 *   - Per-product pricing override management (AJAX save).
 *   - User assignment table on company edit screen.
 *
 * @package WC_B2B\Admin
 */

declare( strict_types=1 );

namespace WC_B2B\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Company_Admin
 */
class Company_Admin {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Custom columns on the Companies list table.
		add_filter( 'manage_b2b_company_posts_columns', [ $this, 'add_list_columns' ] );
		add_action( 'manage_b2b_company_posts_custom_column', [ $this, 'render_list_column' ], 10, 2 );

		// Invoice meta box on company edit screen.
		add_action( 'add_meta_boxes_b2b_company', [ $this, 'add_invoice_meta_box' ] );
		add_action( 'wp_ajax_b2b_send_company_invoice', [ $this, 'ajax_send_invoice' ] );

		// Company members meta box + inline user management AJAX.
		add_action( 'add_meta_boxes_b2b_company', [ $this, 'add_members_meta_box' ] );
		add_action( 'wp_ajax_b2b_create_company_user', [ $this, 'ajax_create_company_user' ] );
		add_action( 'wp_ajax_b2b_add_existing_user_to_company', [ $this, 'ajax_add_existing_user_to_company' ] );
		add_action( 'wp_ajax_b2b_remove_user_from_company', [ $this, 'ajax_remove_user_from_company' ] );
		add_action( 'wp_ajax_b2b_get_company_members', [ $this, 'ajax_get_company_members' ] );
	}

	// -------------------------------------------------------------------------
	// List Table Columns
	// -------------------------------------------------------------------------

	/**
	 * Define custom columns for the Companies list table.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public function add_list_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['b2b_logo']     = __( 'Logo', 'wc-b2b-print-manager' );
				$new['b2b_pricing']  = __( 'Pricing Rule', 'wc-b2b-print-manager' );
				$new['b2b_approval'] = __( 'Approval', 'wc-b2b-print-manager' );
				$new['b2b_members']  = __( 'Members', 'wc-b2b-print-manager' );
			}
		}
		return $new;
	}

	/**
	 * Render custom column values.
	 *
	 * @param string $column  Column slug.
	 * @param int    $post_id Company post ID.
	 */
	public function render_list_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'b2b_logo':
				$thumb = get_the_post_thumbnail( $post_id, [ 50, 50 ] );
				echo $thumb ?: '<span aria-label="' . esc_attr__( 'No logo', 'wc-b2b-print-manager' ) . '">—</span>';
				break;

			case 'b2b_pricing':
				$rule = \WC_B2B\Company_Manager::get_company_pricing( $post_id );
				if ( 0.0 === $rule['value'] ) {
					esc_html_e( 'No rule', 'wc-b2b-print-manager' );
					break;
				}
				if ( 'percentage' === $rule['type'] ) {
					printf( esc_html__( '%s%% discount', 'wc-b2b-print-manager' ), esc_html( (string) $rule['value'] ) );
				} else {
					printf(
						esc_html__( 'Fixed price: %s', 'wc-b2b-print-manager' ),
						esc_html( wc_price( $rule['value'] ) )
					);
				}
				break;

			case 'b2b_approval':
				echo \WC_B2B\Company_Manager::requires_approval( $post_id )
					? '<span class="b2b-badge b2b-badge--warning">' . esc_html__( 'Required', 'wc-b2b-print-manager' ) . '</span>'
					: '<span class="b2b-badge b2b-badge--success">' . esc_html__( 'Auto-approve', 'wc-b2b-print-manager' ) . '</span>';
				break;

			case 'b2b_members':
				$count = count( \WC_B2B\Company_Manager::get_company_users( $post_id ) );
				echo esc_html( (string) $count );
				break;
		}
	}

	// -------------------------------------------------------------------------
	// Invoice Meta Box
	// -------------------------------------------------------------------------

	/**
	 * Register the "Send Invoice" meta box in the company edit sidebar.
	 */
	public function add_invoice_meta_box(): void {
		add_meta_box(
			'b2b_invoice',
			__( 'Send Monthly Invoice', 'wc-b2b-print-manager' ),
			[ $this, 'render_invoice_meta_box' ],
			\WC_B2B\Company_Manager::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the invoice meta box.
	 *
	 * @param \WP_Post $post Company post.
	 */
	public function render_invoice_meta_box( \WP_Post $post ): void {
		// Default to last month.
		$last_month_ts = strtotime( 'first day of last month' );
		$default_month = (int) date( 'n', $last_month_ts );
		$default_year  = (int) date( 'Y', $last_month_ts );

		$last_sent   = get_post_meta( $post->ID, '_b2b_last_invoice_sent', true );
		$last_period = get_post_meta( $post->ID, '_b2b_last_invoice_period', true );
		?>
		<div id="b2b-invoice-box" data-company="<?php echo esc_attr( $post->ID ); ?>">
			<?php wp_nonce_field( 'b2b_invoice_nonce', 'b2b_invoice_nonce' ); ?>

			<p style="margin-top:0">
				<label style="display:block;font-weight:600;margin-bottom:4px"><?php esc_html_e( 'Month', 'wc-b2b-print-manager' ); ?></label>
				<select id="b2b-invoice-month" style="width:100%">
					<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $default_month, $m ); ?>>
							<?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?>
						</option>
					<?php endfor; ?>
				</select>
			</p>

			<p>
				<label style="display:block;font-weight:600;margin-bottom:4px"><?php esc_html_e( 'Year', 'wc-b2b-print-manager' ); ?></label>
				<input type="number" id="b2b-invoice-year" value="<?php echo esc_attr( $default_year ); ?>"
					   min="2020" max="<?php echo esc_attr( (string) ( $default_year + 1 ) ); ?>"
					   style="width:100%" />
			</p>

			<p>
				<button type="button" class="button button-primary" id="b2b-send-invoice-btn" style="width:100%">
					<?php esc_html_e( 'Send Invoice', 'wc-b2b-print-manager' ); ?>
				</button>
			</p>

			<p id="b2b-invoice-msg" style="margin:4px 0;font-size:13px"></p>

			<?php if ( $last_sent && $last_period ) : ?>
				<p class="description" style="margin-top:8px;font-size:12px;color:#6b7280">
					<?php
					printf(
						/* translators: 1: date, 2: month+year */
						esc_html__( 'Last sent: %1$s for %2$s', 'wc-b2b-print-manager' ),
						esc_html( date_i18n( get_option( 'date_format' ), strtotime( $last_sent ) ) ),
						esc_html( date_i18n( 'F Y', strtotime( $last_period . '-01' ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX: send a monthly invoice for a company.
	 *
	 * POST: company_id, month, year, _nonce (b2b_invoice_nonce)
	 */
	public function ajax_send_invoice(): void {
		if (
			! isset( $_POST['_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['_nonce'] ), 'b2b_invoice_nonce' )
		) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! current_user_can( 'manage_companies' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$company_id = (int) sanitize_text_field( wp_unslash( $_POST['company_id'] ?? '' ) );
		$month      = (int) sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) );
		$year       = (int) sanitize_text_field( wp_unslash( $_POST['year'] ?? '' ) );

		if ( $month < 1 || $month > 12 || $year < 2000 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid month or year.', 'wc-b2b-print-manager' ) ] );
		}

		$result = \WC_B2B\Invoice_Manager::send_invoice( $company_id, $month, $year );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$period = date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
		$orders = \WC_B2B\Invoice_Manager::get_orders_for_period( $company_id, $month, $year );

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: 1: order count, 2: period */
					_n(
						'Invoice sent — %1$d order for %2$s.',
						'Invoice sent — %1$d orders for %2$s.',
						count( $orders ),
						'wc-b2b-print-manager'
					),
					count( $orders ),
					$period
				),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Members Meta Box
	// -------------------------------------------------------------------------

	/**
	 * Register the company members meta box.
	 * Placed in the 'normal' context (main column) so there is enough room
	 * for the create-user and add-existing-user forms.
	 */
	public function add_members_meta_box(): void {
		add_meta_box(
			'b2b_company_members',
			__( 'Company Members', 'wc-b2b-print-manager' ),
			[ $this, 'render_members_meta_box' ],
			\WC_B2B\Company_Manager::POST_TYPE,
			'normal',   // Was 'side' — promoted so the forms have enough width.
			'default'
		);
	}

	/**
	 * Render the full inline user management panel.
	 *
	 * Sections:
	 *   1. Current members table with role badge + Remove button.
	 *   2. Add Existing User — search by email, then assign.
	 *   3. Create New User   — name, email, role, auto-generate password.
	 *
	 * @param \WP_Post $post Company post.
	 */
	public function render_members_meta_box( \WP_Post $post ): void {
		$members      = \WC_B2B\Company_Manager::get_company_users( $post->ID );
		$member_nonce = wp_create_nonce( 'b2b_member_management' );
		?>
		<div class="b2b-members-panel" data-company="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( $member_nonce ); ?>">

			<?php /* ── 1. Current members ──────────────────────────────── */ ?>
			<div class="b2b-members-current">
				<h4><?php esc_html_e( 'Current Members', 'wc-b2b-print-manager' ); ?></h4>

				<?php if ( empty( $members ) ) : ?>
					<p class="b2b-no-members"><?php esc_html_e( 'No users assigned to this company yet.', 'wc-b2b-print-manager' ); ?></p>
				<?php else : ?>
					<table class="widefat fixed striped b2b-members-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'wc-b2b-print-manager' ); ?></th>
								<th><?php esc_html_e( 'Email', 'wc-b2b-print-manager' ); ?></th>
								<th><?php esc_html_e( 'Role', 'wc-b2b-print-manager' ); ?></th>
								<th style="width:80px;"><?php esc_html_e( 'Actions', 'wc-b2b-print-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="b2b-members-tbody">
							<?php foreach ( $members as $member ) : ?>
								<tr data-user-id="<?php echo esc_attr( $member->ID ); ?>">
									<td>
										<a href="<?php echo esc_url( get_edit_user_link( $member->ID ) ); ?>">
											<?php echo esc_html( $member->display_name ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $member->user_email ); ?></td>
									<td>
										<span class="b2b-role-badge">
											<?php echo esc_html( \WC_B2B\Role_Manager::get_role_label( $member->ID ) ); ?>
										</span>
									</td>
									<td>
										<button type="button"
												class="button button-small b2b-remove-member"
												data-user="<?php echo esc_attr( $member->ID ); ?>">
											<?php esc_html_e( 'Remove', 'wc-b2b-print-manager' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<hr style="margin:18px 0;" />

			<div class="b2b-members-forms" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

				<?php /* ── 2. Add existing user ────────────────────────── */ ?>
				<div class="b2b-add-existing-user">
					<h4><?php esc_html_e( 'Add Existing User', 'wc-b2b-print-manager' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Enter the email address of an existing WordPress user to add them to this company.', 'wc-b2b-print-manager' ); ?>
					</p>
					<table class="form-table b2b-inline-form">
						<tr>
							<th><label for="b2b-existing-user-email"><?php esc_html_e( 'Email', 'wc-b2b-print-manager' ); ?></label></th>
							<td>
								<input type="email" id="b2b-existing-user-email"
									   placeholder="<?php esc_attr_e( 'user@example.com', 'wc-b2b-print-manager' ); ?>"
									   class="regular-text" />
							</td>
						</tr>
						<tr>
							<th><label for="b2b-existing-user-role"><?php esc_html_e( 'Role', 'wc-b2b-print-manager' ); ?></label></th>
							<td>
								<?php echo $this->role_select( 'b2b-existing-user-role', 'agent' ); ?>
							</td>
						</tr>
					</table>
					<button type="button" class="button button-primary" id="b2b-add-existing-user-btn">
						<?php esc_html_e( 'Add to Company', 'wc-b2b-print-manager' ); ?>
					</button>
					<span class="b2b-form-msg" id="b2b-existing-user-msg"></span>
				</div>

				<?php /* ── 3. Create new user ──────────────────────────── */ ?>
				<div class="b2b-create-new-user">
					<h4><?php esc_html_e( 'Create New User', 'wc-b2b-print-manager' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Create a brand-new WordPress account and immediately assign them to this company.', 'wc-b2b-print-manager' ); ?>
					</p>
					<table class="form-table b2b-inline-form">
						<tr>
							<th><label for="b2b-new-first-name"><?php esc_html_e( 'First Name', 'wc-b2b-print-manager' ); ?></label></th>
							<td><input type="text" id="b2b-new-first-name" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="b2b-new-last-name"><?php esc_html_e( 'Last Name', 'wc-b2b-print-manager' ); ?></label></th>
							<td><input type="text" id="b2b-new-last-name" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="b2b-new-email"><?php esc_html_e( 'Email', 'wc-b2b-print-manager' ); ?></label></th>
							<td>
								<input type="email" id="b2b-new-email"
									   placeholder="<?php esc_attr_e( 'user@example.com', 'wc-b2b-print-manager' ); ?>"
									   class="regular-text" />
							</td>
						</tr>
						<tr>
							<th><label for="b2b-new-role"><?php esc_html_e( 'Role', 'wc-b2b-print-manager' ); ?></label></th>
							<td><?php echo $this->role_select( 'b2b-new-role', 'agent' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Password', 'wc-b2b-print-manager' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="b2b-new-send-password" value="1" checked />
									<?php esc_html_e( 'Auto-generate and email password to user', 'wc-b2b-print-manager' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<button type="button" class="button button-primary" id="b2b-create-user-btn">
						<?php esc_html_e( 'Create &amp; Add to Company', 'wc-b2b-print-manager' ); ?>
					</button>
					<span class="b2b-form-msg" id="b2b-create-user-msg"></span>
				</div>

			</div><!-- .b2b-members-forms -->
		</div><!-- .b2b-members-panel -->
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: Member Management
	// -------------------------------------------------------------------------

	/**
	 * Verify nonce and capability for member management AJAX calls.
	 * Calls wp_send_json_error and exits on failure.
	 */
	private function verify_member_request(): void {
		if (
			! isset( $_POST['_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['_nonce'] ), 'b2b_member_management' )
		) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! current_user_can( 'manage_companies' ) && ! current_user_can( 'manage_company_agents' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}
	}

	/**
	 * AJAX: Create a new WP user and assign them to a company.
	 *
	 * POST: company_id, first_name, last_name, email, role, send_password, _nonce
	 */
	public function ajax_create_company_user(): void {
		$this->verify_member_request();

		$company_id   = (int) sanitize_text_field( wp_unslash( $_POST['company_id'] ?? '' ) );
		$first_name   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email        = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$role         = sanitize_text_field( wp_unslash( $_POST['role'] ?? 'agent' ) );
		$send_pass    = ! empty( $_POST['send_password'] );

		// Validate company.
		if ( ! \WC_B2B\Company_Manager::get_company( $company_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid company.', 'wc-b2b-print-manager' ) ] );
		}

		// Validate email.
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'wc-b2b-print-manager' ) ] );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'A user with that email address already exists. Use "Add Existing User" instead.', 'wc-b2b-print-manager' ) ] );
		}

		// Validate role — only allow our custom B2B roles.
		$allowed_roles = [ 'agent', 'company_admin' ];
		if ( ! in_array( $role, $allowed_roles, true ) ) {
			$role = 'agent';
		}

		// Build username from email local-part, ensure uniqueness.
		$username = sanitize_user( strstr( $email, '@', true ), true );
		if ( username_exists( $username ) ) {
			$username = $username . '_' . wp_generate_password( 4, false );
		}

		$password = wp_generate_password( 16, true, false );

		$user_id = wp_insert_user(
			[
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( "$first_name $last_name" ) ?: $username,
				'role'         => $role,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( [ 'message' => $user_id->get_error_message() ] );
		}

		// Assign to company.
		\WC_B2B\Company_Manager::assign_user_to_company( $user_id, $company_id );

		// Email credentials to the new user if requested.
		if ( $send_pass ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		$user = get_user_by( 'id', $user_id );

		wp_send_json_success(
			[
				'message'      => __( 'User created and added to company.', 'wc-b2b-print-manager' ),
				'user_id'      => $user_id,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'role_label'   => \WC_B2B\Role_Manager::get_role_label( $user_id ),
				'edit_url'     => get_edit_user_link( $user_id ),
			]
		);
	}

	/**
	 * AJAX: Find an existing WP user by email and assign them to a company.
	 *
	 * POST: company_id, email, role, _nonce
	 */
	public function ajax_add_existing_user_to_company(): void {
		$this->verify_member_request();

		$company_id = (int) sanitize_text_field( wp_unslash( $_POST['company_id'] ?? '' ) );
		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$role       = sanitize_text_field( wp_unslash( $_POST['role'] ?? 'agent' ) );

		if ( ! \WC_B2B\Company_Manager::get_company( $company_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid company.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'wc-b2b-print-manager' ) ] );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			wp_send_json_error( [ 'message' => __( 'No user found with that email address.', 'wc-b2b-print-manager' ) ] );
		}

		// Prevent adding a super admin to a company.
		if ( \WC_B2B\Role_Manager::is_super_admin( $user->ID ) ) {
			wp_send_json_error( [ 'message' => __( 'Administrators cannot be assigned to a company.', 'wc-b2b-print-manager' ) ] );
		}

		// Check not already in a different company (company admins can't steal users).
		$existing_company = \WC_B2B\Company_Manager::get_user_company_id( $user->ID );
		if (
			$existing_company &&
			$existing_company !== $company_id &&
			! current_user_can( 'manage_companies' )
		) {
			wp_send_json_error( [ 'message' => __( 'This user already belongs to another company.', 'wc-b2b-print-manager' ) ] );
		}

		// Validate and apply role.
		$allowed_roles = [ 'agent', 'company_admin' ];
		if ( in_array( $role, $allowed_roles, true ) ) {
			$user->set_role( $role );
		}

		\WC_B2B\Company_Manager::assign_user_to_company( $user->ID, $company_id );

		wp_send_json_success(
			[
				'message'      => __( 'User added to company.', 'wc-b2b-print-manager' ),
				'user_id'      => $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'role_label'   => \WC_B2B\Role_Manager::get_role_label( $user->ID ),
				'edit_url'     => get_edit_user_link( $user->ID ),
			]
		);
	}

	/**
	 * AJAX: Remove a user from a company (clears the company_id user meta).
	 *
	 * POST: company_id, user_id, _nonce
	 */
	public function ajax_remove_user_from_company(): void {
		$this->verify_member_request();

		$company_id = (int) sanitize_text_field( wp_unslash( $_POST['company_id'] ?? '' ) );
		$user_id    = (int) sanitize_text_field( wp_unslash( $_POST['user_id'] ?? '' ) );

		// Company admins can only remove users from their own company.
		if ( ! current_user_can( 'manage_companies' ) ) {
			$caller_company = \WC_B2B\Company_Manager::get_user_company_id();
			if ( $caller_company !== $company_id ) {
				wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
			}
		}

		$user_company = \WC_B2B\Company_Manager::get_user_company_id( $user_id );
		if ( $user_company !== $company_id ) {
			wp_send_json_error( [ 'message' => __( 'This user does not belong to this company.', 'wc-b2b-print-manager' ) ] );
		}

		delete_user_meta( $user_id, \WC_B2B\Company_Manager::USER_META_COMPANY );

		wp_send_json_success( [ 'message' => __( 'User removed from company.', 'wc-b2b-print-manager' ) ] );
	}

	/**
	 * AJAX: Return the current member list for a company (used to refresh the table).
	 *
	 * GET: company_id, _nonce
	 */
	public function ajax_get_company_members(): void {
		if (
			! isset( $_GET['_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_GET['_nonce'] ), 'b2b_member_management' )
		) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! current_user_can( 'manage_companies' ) && ! current_user_can( 'manage_company_agents' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$company_id = (int) sanitize_text_field( wp_unslash( $_GET['company_id'] ?? '' ) );
		$members    = \WC_B2B\Company_Manager::get_company_users( $company_id );

		$data = array_map(
			fn( $u ) => [
				'user_id'      => $u->ID,
				'display_name' => $u->display_name,
				'email'        => $u->user_email,
				'role_label'   => \WC_B2B\Role_Manager::get_role_label( $u->ID ),
				'edit_url'     => get_edit_user_link( $u->ID ),
			],
			$members
		);

		wp_send_json_success( $data );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Render a role <select> element for the member forms.
	 *
	 * @param string $id           HTML id attribute.
	 * @param string $selected_val Currently selected value.
	 * @return string HTML string.
	 */
	private function role_select( string $id, string $selected_val = 'agent' ): string {
		$options = [
			'agent'         => __( 'Agent', 'wc-b2b-print-manager' ),
			'company_admin' => __( 'Company Admin', 'wc-b2b-print-manager' ),
		];

		$html = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
		foreach ( $options as $value => $label ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected_val, $value, false ),
				esc_html( $label )
			);
		}
		$html .= '</select>';
		return $html;
	}
}
