/**
 * WC B2B Print Manager — Admin JavaScript
 * ============================================================
 * Handles:
 *   - Order approve / reject actions on approval queue page & order edit page.
 *   - Per-product pricing rule CRUD on the company edit screen.
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

        var $row    = $( '[data-order-id="' + orderId + '"]' );
        var $buttons = $row.find( '.b2b-approve-order, .b2b-reject-order' );

        $buttons.prop( 'disabled', true ).text( wcB2BAdmin.i18n.processing );

        $.post( wcB2BAdmin.ajax_url, data )
            .done( function ( response ) {
                if ( response.success ) {
                    // Reload the page to reflect new status.
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

    // Approve button.
    $( document ).on( 'click', '.b2b-approve-order', function () {
        if ( ! window.confirm( wcB2BAdmin.i18n.confirm_approve ) ) {
            return;
        }
        sendOrderAction( 'b2b_approve_order', $( this ).data( 'order' ) );
    } );

    // Reject button.
    $( document ).on( 'click', '.b2b-reject-order', function () {
        if ( ! window.confirm( wcB2BAdmin.i18n.confirm_reject ) ) {
            return;
        }
        var reason = window.prompt( wcB2BAdmin.i18n.reason_prompt, '' );
        if ( reason === null ) {
            return; // User cancelled.
        }
        sendOrderAction( 'b2b_reject_order', $( this ).data( 'order' ), reason );
    } );

    // ── Per-product pricing meta box ─────────────────────────────────────────

    var $pricingBox = $( '#b2b-product-pricing' );

    if ( $pricingBox.length ) {
        var companyId = $pricingBox.data( 'company' );

        /**
         * Load existing per-product pricing rules via AJAX.
         */
        function loadProductRules() {
            $.get( wcB2BAdmin.ajax_url, {
                action:     'b2b_get_product_pricings',
                company_id: companyId,
                _nonce:     $( '#b2b_product_pricing_nonce' ).val(),
            } )
                .done( function ( response ) {
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

        // Initial load.
        loadProductRules();

        // Add rule.
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
            } )
                .done( function ( response ) {
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

} )( jQuery );
