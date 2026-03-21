/**
 * WC B2B Print Manager — Admin JavaScript
 * ============================================================
 * Handles:
 *   - Order approve / reject actions on approval queue page & order edit page.
 *   - Per-product pricing rule CRUD on the company edit screen.
 *   - Inline company member management (create new user / add existing / remove).
 */

/* global wcB2BAdmin, jQuery */

( function ( $ ) {
    'use strict';

    // ── Order approve / reject ────────────────────────────────────────────────

    /**
     * Send an order action (approve / reject) via AJAX and update the row.
     *
     * @param {string} action    'b2b_approve_order' | 'b2b_reject_order'
     * @param {number} orderId   WooCommerce order ID.
     * @param {string} [reason]  Optional rejection reason.
     */
    function sendOrderAction( action, orderId, reason ) {
        var data = {
            action:   action,
            order_id: orderId,
            _nonce:   wcB2BAdmin.nonce,
        };

        if ( reason ) {
            data.reason = reason;
        }

        var $row     = $( '[data-order-id="' + orderId + '"]' );
        var $buttons = $row.find( '.b2b-approve-order, .b2b-reject-order' );

        $buttons.prop( 'disabled', true ).text( wcB2BAdmin.i18n.processing );

        $.post( wcB2BAdmin.ajax_url, data )
            .done( function ( response ) {
                if ( response.success ) {
                    window.location.reload();
                } else {
                    alert( response.data.message || 'Error processing request.' );
                    $buttons.prop( 'disabled', false );
                }
            } )
            .fail( function () {
                alert( 'Network error. Please try again.' );
                $buttons.prop( 'disabled', false );
            } );
    }

    $( document ).on( 'click', '.b2b-approve-order', function () {
        if ( ! window.confirm( wcB2BAdmin.i18n.confirm_approve ) ) { return; }
        sendOrderAction( 'b2b_approve_order', $( this ).data( 'order' ) );
    } );

    $( document ).on( 'click', '.b2b-reject-order', function () {
        if ( ! window.confirm( wcB2BAdmin.i18n.confirm_reject ) ) { return; }
        var reason = window.prompt( wcB2BAdmin.i18n.reason_prompt, '' );
        if ( reason === null ) { return; }
        sendOrderAction( 'b2b_reject_order', $( this ).data( 'order' ), reason );
    } );

    // ── Per-product pricing meta box ─────────────────────────────────────────

    var $pricingBox = $( '#b2b-product-pricing' );

    if ( $pricingBox.length ) {
        var companyId = $pricingBox.data( 'company' );

        function loadProductRules() {
            $.get( wcB2BAdmin.ajax_url, {
                action:     'b2b_get_product_pricings',
                company_id: companyId,
                _nonce:     $( '#b2b_product_pricing_nonce' ).val(),
            } ).done( function ( response ) {
                var $tbody = $( '#b2b-product-rules-table tbody' );
                $tbody.empty();

                if ( ! response.success || ! response.data.length ) {
                    $tbody.html( '<tr><td colspan="4">No per-product rules set.</td></tr>' );
                    return;
                }

                $.each( response.data, function ( i, rule ) {
                    var valueDisplay = rule.pricing_type === 'percentage'
                        ? rule.pricing_value + '%'
                        : rule.pricing_value;

                    $tbody.append(
                        '<tr data-rule-id="' + rule.id + '">' +
                            '<td>' + $( '<span>' ).text( rule.product_name ).html() + '</td>' +
                            '<td>' + ( rule.pricing_type === 'percentage' ? 'Discount %' : 'Fixed Price' ) + '</td>' +
                            '<td>' + valueDisplay + '</td>' +
                            '<td><button type="button" class="button button-small b2b-delete-rule" data-id="' + rule.id + '">Remove</button></td>' +
                        '</tr>'
                    );
                } );
            } );
        }

        loadProductRules();

        $( '#b2b-add-product-rule' ).on( 'click', function () {
            var $productField = $( '#b2b-product-search' );
            var productId     = $productField.val();
            var pricingType   = $( '#b2b-pricing-type-product' ).val();
            var pricingValue  = $( '#b2b-pricing-value-product' ).val();

            if ( ! productId || ! pricingValue ) {
                alert( 'Please select a product and enter a value.' );
                return;
            }

            $.post( wcB2BAdmin.ajax_url, {
                action:        'b2b_save_product_pricing',
                company_id:    companyId,
                product_id:    productId,
                pricing_type:  pricingType,
                pricing_value: pricingValue,
                _nonce:        $( '#b2b_product_pricing_nonce' ).val(),
            } ).done( function ( response ) {
                if ( response.success ) {
                    loadProductRules();
                    $productField.val( '' ).trigger( 'change' );
                    $( '#b2b-pricing-value-product' ).val( '' );
                } else {
                    alert( response.data.message || 'Failed to save rule.' );
                }
            } );
        } );
    }

    // ── Company Members Panel ─────────────────────────────────────────────────

    var $membersPanel = $( '.b2b-members-panel' );

    if ( $membersPanel.length ) {
        var panelCompanyId = $membersPanel.data( 'company' );
        var panelNonce     = $membersPanel.data( 'nonce' );

        // ── Helpers ──────────────────────────────────────────────────────────

        /**
         * Show a status message next to a form button.
         *
         * @param {jQuery} $el    The message <span> element.
         * @param {string} msg    Text to display.
         * @param {bool}   isErr  True = red, false = green.
         */
        function showMsg( $el, msg, isErr ) {
            $el.text( msg ).css( 'color', isErr ? '#dc2626' : '#059669' );
            setTimeout( function () { $el.text( '' ); }, 5000 );
        }

        /**
         * Build an HTML table row for a member object returned by AJAX.
         *
         * @param {{user_id, display_name, email, role_label, edit_url}} member
         * @return {string} HTML string.
         */
        function buildMemberRow( member ) {
            return '<tr data-user-id="' + member.user_id + '">' +
                '<td><a href="' + $( '<span>' ).text( member.edit_url ).html() + '">' +
                    $( '<span>' ).text( member.display_name ).html() +
                '</a></td>' +
                '<td>' + $( '<span>' ).text( member.email ).html() + '</td>' +
                '<td><span class="b2b-role-badge">' + $( '<span>' ).text( member.role_label ).html() + '</span></td>' +
                '<td><button type="button" class="button button-small b2b-remove-member" data-user="' + member.user_id + '">' +
                    'Remove' +
                '</button></td>' +
            '</tr>';
        }

        /**
         * Append a new member row (or replace the "no members" placeholder).
         *
         * @param {{user_id, display_name, email, role_label, edit_url}} member
         */
        function appendMemberRow( member ) {
            var $tbody = $( '#b2b-members-tbody' );

            // If the table doesn't exist yet, build it from scratch.
            if ( ! $tbody.length ) {
                var table =
                    '<table class="widefat fixed striped b2b-members-table">' +
                        '<thead><tr>' +
                            '<th>Name</th><th>Email</th><th>Role</th><th style="width:80px;">Actions</th>' +
                        '</tr></thead>' +
                        '<tbody id="b2b-members-tbody"></tbody>' +
                    '</table>';

                $( '.b2b-no-members' ).replaceWith( table );
                $tbody = $( '#b2b-members-tbody' );
            }

            // If the placeholder row is there, remove it first.
            $tbody.find( '.b2b-no-members-row' ).remove();

            // Avoid duplicate rows.
            $tbody.find( '[data-user-id="' + member.user_id + '"]' ).remove();

            $tbody.append( buildMemberRow( member ) );
        }

        // ── Remove member ─────────────────────────────────────────────────────

        $( document ).on( 'click', '.b2b-remove-member', function () {
            if ( ! window.confirm( wcB2BAdmin.i18n.confirm_remove ) ) { return; }

            var $btn    = $( this );
            var userId  = $btn.data( 'user' );
            var $row    = $btn.closest( 'tr' );

            $btn.prop( 'disabled', true ).text( wcB2BAdmin.i18n.processing );

            $.post( wcB2BAdmin.ajax_url, {
                action:     'b2b_remove_user_from_company',
                company_id: panelCompanyId,
                user_id:    userId,
                _nonce:     panelNonce,
            } ).done( function ( response ) {
                if ( response.success ) {
                    $row.fadeOut( 250, function () {
                        $( this ).remove();
                        // Show placeholder if table is now empty.
                        if ( ! $( '#b2b-members-tbody tr' ).length ) {
                            $( '#b2b-members-tbody' ).closest( 'table' ).replaceWith(
                                '<p class="b2b-no-members">' + wcB2BAdmin.i18n.no_members + '</p>'
                            );
                        }
                    } );
                } else {
                    alert( response.data.message || 'Error.' );
                    $btn.prop( 'disabled', false ).text( 'Remove' );
                }
            } ).fail( function () {
                alert( 'Network error.' );
                $btn.prop( 'disabled', false ).text( 'Remove' );
            } );
        } );

        // ── Add existing user ─────────────────────────────────────────────────

        $( '#b2b-add-existing-user-btn' ).on( 'click', function () {
            var $btn   = $( this );
            var $msg   = $( '#b2b-existing-user-msg' );
            var email  = $( '#b2b-existing-user-email' ).val().trim();
            var role   = $( '#b2b-existing-user-role' ).val();

            if ( ! email ) {
                showMsg( $msg, 'Please enter an email address.', true );
                return;
            }

            $btn.prop( 'disabled', true ).text( wcB2BAdmin.i18n.adding_user );

            $.post( wcB2BAdmin.ajax_url, {
                action:     'b2b_add_existing_user_to_company',
                company_id: panelCompanyId,
                email:      email,
                role:       role,
                _nonce:     panelNonce,
            } ).done( function ( response ) {
                if ( response.success ) {
                    appendMemberRow( response.data );
                    $( '#b2b-existing-user-email' ).val( '' );
                    showMsg( $msg, response.data.message, false );
                } else {
                    showMsg( $msg, response.data.message || 'Error.', true );
                }
            } ).fail( function () {
                showMsg( $msg, 'Network error.', true );
            } ).always( function () {
                $btn.prop( 'disabled', false ).text( 'Add to Company' );
            } );
        } );

        // ── Create new user ───────────────────────────────────────────────────

        $( '#b2b-create-user-btn' ).on( 'click', function () {
            var $btn      = $( this );
            var $msg      = $( '#b2b-create-user-msg' );
            var firstName = $( '#b2b-new-first-name' ).val().trim();
            var lastName  = $( '#b2b-new-last-name' ).val().trim();
            var email     = $( '#b2b-new-email' ).val().trim();
            var role      = $( '#b2b-new-role' ).val();
            var sendPass  = $( '#b2b-new-send-password' ).is( ':checked' ) ? '1' : '';

            if ( ! email ) {
                showMsg( $msg, 'Email address is required.', true );
                return;
            }

            $btn.prop( 'disabled', true ).text( wcB2BAdmin.i18n.creating_user );

            $.post( wcB2BAdmin.ajax_url, {
                action:        'b2b_create_company_user',
                company_id:    panelCompanyId,
                first_name:    firstName,
                last_name:     lastName,
                email:         email,
                role:          role,
                send_password: sendPass,
                _nonce:        panelNonce,
            } ).done( function ( response ) {
                if ( response.success ) {
                    appendMemberRow( response.data );
                    // Clear the form.
                    $( '#b2b-new-first-name, #b2b-new-last-name, #b2b-new-email' ).val( '' );
                    showMsg( $msg, response.data.message, false );
                } else {
                    showMsg( $msg, response.data.message || 'Error.', true );
                }
            } ).fail( function () {
                showMsg( $msg, 'Network error.', true );
            } ).always( function () {
                $btn.prop( 'disabled', false ).text( 'Create & Add to Company' );
            } );
        } );

        // Allow submitting both forms with Enter key in email fields.
        $( '#b2b-existing-user-email' ).on( 'keypress', function ( e ) {
            if ( 13 === e.which ) { $( '#b2b-add-existing-user-btn' ).trigger( 'click' ); }
        } );
        $( '#b2b-new-email' ).on( 'keypress', function ( e ) {
            if ( 13 === e.which ) { $( '#b2b-create-user-btn' ).trigger( 'click' ); }
        } );
    }

} )( jQuery );
