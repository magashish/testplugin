/**
 * WC B2B Print Manager — Admin JavaScript
 * ============================================================
 * Handles:
 *   - Order approve / reject actions on approval queue page & order edit page.
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

    // ── Invoice sending ──────────────────────────────────────────────────────

    /**
     * Send an invoice for one company and call back with result.
     *
     * @param {number}   companyId
     * @param {number}   month
     * @param {number}   year
     * @param {Function} done  Called with (ok, message).
     */
    function sendInvoice( companyId, month, year, done ) {
        $.post( wcB2BAdmin.ajax_url, {
            action:     'b2b_send_company_invoice',
            company_id: companyId,
            month:      month,
            year:       year,
            _nonce:     wcB2BAdmin.invoice_nonce,
        } )
        .done( function ( r ) {
            done( r.success, r.success ? r.data.message : ( r.data.message || 'Error.' ) );
        } )
        .fail( function () { done( false, 'Network error.' ); } );
    }

    // Company edit screen — meta box button.
    $( document ).on( 'click', '#b2b-send-invoice-btn', function () {
        var $btn  = $( this );
        var $msg  = $( '#b2b-invoice-msg' );
        var cid   = $( '#b2b-invoice-box' ).data( 'company' );
        var month = $( '#b2b-invoice-month' ).val();
        var year  = $( '#b2b-invoice-year' ).val();

        $btn.prop( 'disabled', true ).text( wcB2BAdmin.i18n.processing );
        $msg.text( '' ).css( 'color', '' );

        sendInvoice( cid, month, year, function ( ok, msg ) {
            $msg.text( msg ).css( 'color', ok ? '#059669' : '#dc2626' );
            $btn.prop( 'disabled', false ).text( 'Send Invoice' );
        } );
    } );

    // Invoices page — per-row send button.
    $( document ).on( 'click', '.b2b-send-invoice-row', function () {
        var $btn   = $( this );
        var $status = $btn.siblings( '.b2b-row-invoice-status' );
        var cid    = $btn.data( 'company' );
        var month  = $( '#b2b-bulk-invoice-month' ).val();
        var year   = $( '#b2b-bulk-invoice-year' ).val();

        $btn.prop( 'disabled', true );
        $status.text( wcB2BAdmin.i18n.processing ).css( 'color', '' );

        sendInvoice( cid, month, year, function ( ok, msg ) {
            $status.text( msg ).css( 'color', ok ? '#059669' : '#dc2626' );
            $btn.prop( 'disabled', false );
        } );
    } );

    // Invoices page — "Send to All" button.
    $( document ).on( 'click', '#b2b-send-all-invoices', function () {
        var $btn    = $( this );
        var $status = $( '#b2b-bulk-invoice-status' );
        var month   = $( '#b2b-bulk-invoice-month' ).val();
        var year    = $( '#b2b-bulk-invoice-year' ).val();
        var rows    = $( '.b2b-send-invoice-row' );

        if ( ! rows.length ) { return; }

        $btn.prop( 'disabled', true );
        $status.text( '0 / ' + rows.length + ' sent…' ).css( 'color', '' );

        var sent = 0;
        var errors = 0;

        function next( idx ) {
            if ( idx >= rows.length ) {
                var msg = sent + ' sent';
                if ( errors ) { msg += ', ' + errors + ' failed'; }
                $status.text( msg ).css( 'color', errors ? '#dc2626' : '#059669' );
                $btn.prop( 'disabled', false );
                return;
            }

            var $row = $( rows[ idx ] );
            var cid  = $row.data( 'company' );
            var $rowStatus = $row.siblings( '.b2b-row-invoice-status' );

            $row.prop( 'disabled', true );
            $rowStatus.text( wcB2BAdmin.i18n.processing ).css( 'color', '' );

            sendInvoice( cid, month, year, function ( ok, msg ) {
                $rowStatus.text( msg ).css( 'color', ok ? '#059669' : '#dc2626' );
                $row.prop( 'disabled', false );
                ok ? sent++ : errors++;
                $status.text( ( sent + errors ) + ' / ' + rows.length + '…' );
                next( idx + 1 );
            } );
        }

        next( 0 );
    } );

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
