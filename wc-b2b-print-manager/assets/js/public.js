/**
 * WC B2B Print Manager — Public / Front-End JavaScript
 * ============================================================
 * Handles:
 *   - Artwork library: upload, delete, display.
 *   - Order approve / reject (company admin front-end).
 *   - Agent management: assign / remove via email lookup.
 */

/* global wcB2BPublic, jQuery */

( function ( $ ) {
    'use strict';

    var ajax_url    = wcB2BPublic.ajax_url;
    var artworkNonce = wcB2BPublic.nonce;
    var orderNonce   = wcB2BPublic.order_nonce;

    // ── Artwork: upload ──────────────────────────────────────────────────────

    $( document ).on( 'click', '#b2b-upload-artwork', function () {
        var $btn    = $( this );
        var $status = $btn.siblings( '.b2b-upload-status' );
        var $file   = $( '#b2b-artwork-upload-file' )[0];
        var $title  = $( '#b2b-artwork-title' );

        if ( ! $file || ! $file.files.length ) {
            $status.text( 'Please select a file first.' ).css( 'color', '#ef4444' );
            return;
        }

        $btn.prop( 'disabled', true );
        $status.text( wcB2BPublic.i18n.uploading ).css( 'color', '' );

        var formData = new FormData();
        formData.append( 'action', 'b2b_upload_artwork' );
        formData.append( '_nonce', artworkNonce );
        formData.append( 'title', $title.val() );
        formData.append( 'artwork_file', $file.files[0] );

        $.ajax( {
            url:         ajax_url,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,
        } )
            .done( function ( response ) {
                if ( response.success ) {
                    $status.text( 'Uploaded successfully!' ).css( 'color', '#059669' );
                    appendArtworkCard( response.data );
                    $title.val( '' );
                    $file.value = '';
                    // Remove "no data" placeholder if present.
                    $( '#b2b-artwork-grid .b2b-no-data' ).remove();
                } else {
                    $status.text( response.data.message || 'Upload failed.' ).css( 'color', '#ef4444' );
                }
            } )
            .fail( function () {
                $status.text( 'Network error. Please try again.' ).css( 'color', '#ef4444' );
            } )
            .always( function () {
                $btn.prop( 'disabled', false );
            } );
    } );

    /**
     * Append a new artwork card to the grid.
     *
     * @param {{id, title, file_url, type}} item
     */
    function appendArtworkCard( item ) {
        var ext     = item.file_url.split( '.' ).pop().toLowerCase();
        var isImage = [ 'jpg', 'jpeg', 'png' ].indexOf( ext ) !== -1;

        var preview = isImage
            ? '<img src="' + $( '<span>' ).text( item.file_url ).html() + '" alt="" loading="lazy" />'
            : '<span class="b2b-artwork-icon dashicons dashicons-media-document"></span>';

        var card =
            '<div class="b2b-artwork-item" data-id="' + item.id + '">' +
                '<div class="b2b-artwork-preview">' + preview + '</div>' +
                '<div class="b2b-artwork-info">' +
                    '<strong>' + $( '<span>' ).text( item.title ).html() + '</strong>' +
                    '<span class="b2b-artwork-type">' + ext.toUpperCase() + '</span>' +
                '</div>' +
                '<div class="b2b-artwork-actions">' +
                    '<a href="' + $( '<span>' ).text( item.file_url ).html() + '" class="b2b-btn b2b-btn--sm" target="_blank" download>Download</a>' +
                    '<button class="b2b-btn b2b-btn--sm b2b-btn--danger b2b-delete-artwork" data-id="' + item.id + '">Delete</button>' +
                '</div>' +
            '</div>';

        $( '#b2b-artwork-grid' ).append( card );
    }

    // ── Artwork: delete ──────────────────────────────────────────────────────

    $( document ).on( 'click', '.b2b-delete-artwork', function () {
        if ( ! window.confirm( wcB2BPublic.i18n.confirm_delete ) ) {
            return;
        }

        var $card      = $( this ).closest( '.b2b-artwork-item' );
        var artworkId  = $( this ).data( 'id' );

        $.post( ajax_url, {
            action:     'b2b_delete_artwork',
            artwork_id: artworkId,
            _nonce:     artworkNonce,
        } )
            .done( function ( response ) {
                if ( response.success ) {
                    $card.fadeOut( 300, function () {
                        $( this ).remove();
                        if ( ! $( '#b2b-artwork-grid .b2b-artwork-item' ).length ) {
                            $( '#b2b-artwork-grid' ).html( '<p class="b2b-no-data">No artwork in your library yet.</p>' );
                        }
                    } );
                } else {
                    alert( response.data.message || 'Failed to delete artwork.' );
                }
            } );
    } );

    // ── Order approve / reject (company admin front-end) ─────────────────────

    function sendFrontendOrderAction( action, orderId, reason ) {
        var data = {
            action:   action,
            order_id: orderId,
            _nonce:   orderNonce,
        };

        if ( reason ) {
            data.reason = reason;
        }

        var $row     = $( '[data-order-id="' + orderId + '"]' );
        var $buttons = $row.find( '.b2b-approve-order, .b2b-reject-order' );

        $buttons.prop( 'disabled', true );

        $.post( ajax_url, data )
            .done( function ( response ) {
                if ( response.success ) {
                    $row.fadeOut( 300, function () { $( this ).remove(); } );
                } else {
                    alert( response.data.message || 'Error.' );
                    $buttons.prop( 'disabled', false );
                }
            } )
            .fail( function () {
                alert( 'Network error.' );
                $buttons.prop( 'disabled', false );
            } );
    }

    $( document ).on( 'click', '.b2b-approve-order', function () {
        sendFrontendOrderAction( 'b2b_approve_order', $( this ).data( 'order' ) );
    } );

    $( document ).on( 'click', '.b2b-reject-order', function () {
        if ( ! window.confirm( wcB2BPublic.i18n.confirm_reject ) ) {
            return;
        }
        var reason = window.prompt( wcB2BPublic.i18n.reason_prompt, '' );
        if ( reason === null ) {
            return;
        }
        sendFrontendOrderAction( 'b2b_reject_order', $( this ).data( 'order' ), reason );
    } );

    // ── Agent management ─────────────────────────────────────────────────────

    // Remove agent from company.
    $( document ).on( 'click', '.b2b-remove-agent', function () {
        var $btn   = $( this );
        var userId = $btn.data( 'user' );
        var nonce  = $btn.data( 'nonce' );

        if ( ! window.confirm( 'Remove this agent from your company?' ) ) {
            return;
        }

        $.post( ajax_url, {
            action:  'b2b_remove_agent',
            user_id: userId,
            _nonce:  nonce,
        } )
            .done( function ( response ) {
                if ( response.success ) {
                    $btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
                } else {
                    alert( response.data.message || 'Error.' );
                }
            } );
    } );

    // Assign agent by email.
    $( document ).on( 'click', '#b2b-assign-agent', function () {
        var $btn   = $( this );
        var email  = $( '#b2b-agent-email' ).val().trim();
        var nonce  = $btn.data( 'nonce' );
        var $msg   = $( '.b2b-assign-agent-msg' );

        if ( ! email ) {
            $msg.text( 'Please enter an email address.' ).css( 'color', '#ef4444' );
            return;
        }

        $btn.prop( 'disabled', true );

        // First, look up the user ID by email via a simple AJAX call.
        $.post( ajax_url, {
            action: 'b2b_lookup_user_by_email',
            email:  email,
            _nonce: nonce,
        } )
            .done( function ( response ) {
                if ( ! response.success ) {
                    $msg.text( response.data.message || 'User not found.' ).css( 'color', '#ef4444' );
                    $btn.prop( 'disabled', false );
                    return;
                }

                $.post( ajax_url, {
                    action:  'b2b_assign_agent',
                    user_id: response.data.user_id,
                    _nonce:  nonce,
                } )
                    .done( function ( r ) {
                        if ( r.success ) {
                            $msg.text( r.data.message ).css( 'color', '#059669' );
                            $( '#b2b-agent-email' ).val( '' );
                            // Reload section after short delay.
                            setTimeout( function () { window.location.reload(); }, 1200 );
                        } else {
                            $msg.text( r.data.message || 'Error.' ).css( 'color', '#ef4444' );
                        }
                    } )
                    .always( function () { $btn.prop( 'disabled', false ); } );
            } )
            .fail( function () {
                $msg.text( 'Network error.' ).css( 'color', '#ef4444' );
                $btn.prop( 'disabled', false );
            } );
    } );

} )( jQuery );
