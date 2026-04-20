/**
 * WC B2B Print Manager — Public / Front-End JavaScript
 * ============================================================
 * Handles:
 *   - Artwork library: upload, delete, display.
 *   - Order approve / reject (company admin front-end).
 *   - Team management: add existing, create new, remove employee.
 */

/* global wcB2BPublic, jQuery */

( function ( $ ) {
    'use strict';

    if ( typeof wcB2BPublic === 'undefined' ) {
        return; // script data not localised — bail silently.
    }

    var ajax_url     = wcB2BPublic.ajax_url;
    var artworkNonce = wcB2BPublic.nonce;
    var orderNonce   = wcB2BPublic.order_nonce;
    var teamNonce    = wcB2BPublic.team_nonce;

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

    // ── Team management ──────────────────────────────────────────────────────

    /**
     * Show a status message inside a team form.
     *
     * @param {jQuery} $el   The message <span> element.
     * @param {string} msg   Text to display.
     * @param {bool}   isErr True = red error, false = green success.
     */
    function teamMsg( $el, msg, isErr ) {
        $el.text( msg ).css( 'color', isErr ? '#ef4444' : '#059669' );
        if ( ! isErr ) {
            setTimeout( function () { $el.text( '' ); }, 4000 );
        }
    }

    /**
     * Build and append a new row to the team members table.
     * Creates the table if only the "empty" placeholder exists.
     *
     * @param {{user_id, display_name, email, role_label}} member
     */
    function appendTeamRow( member ) {
        var $tbody = $( '#b2b-team-tbody' );

        if ( ! $tbody.length ) {
            var table =
                '<table class="b2b-table b2b-team-table">' +
                    '<thead><tr>' +
                        '<th>Name</th><th>Email</th><th>Role</th><th></th>' +
                    '</tr></thead>' +
                    '<tbody id="b2b-team-tbody"></tbody>' +
                '</table>';
            $( '.b2b-team-empty' ).replaceWith( table );
            $tbody = $( '#b2b-team-tbody' );
        }

        $tbody.find( '[data-user-id="' + member.user_id + '"]' ).remove();

        $tbody.append(
            '<tr data-user-id="' + member.user_id + '">' +
                '<td>' + $( '<span>' ).text( member.display_name ).html() + '</td>' +
                '<td>' + $( '<span>' ).text( member.email ).html() + '</td>' +
                '<td><span class="b2b-role-chip">' + $( '<span>' ).text( member.role_label ).html() + '</span></td>' +
                '<td><button class="b2b-btn b2b-btn--sm b2b-btn--danger b2b-remove-team-member" data-user="' + member.user_id + '">Remove</button></td>' +
            '</tr>'
        );
    }

    // Remove employee.
    $( document ).on( 'click', '.b2b-remove-team-member', function () {
        if ( ! window.confirm( wcB2BPublic.i18n.confirm_remove_member ) ) { return; }

        var $btn  = $( this );
        var $row  = $btn.closest( 'tr' );
        var userId = $btn.data( 'user' );

        $btn.prop( 'disabled', true );

        $.post( ajax_url, {
            action:  'b2b_frontend_remove_employee',
            user_id: userId,
            _nonce:  teamNonce,
        } )
            .done( function ( response ) {
                if ( response.success ) {
                    $row.fadeOut( 300, function () { $( this ).remove(); } );
                } else {
                    alert( response.data.message || 'Error.' );
                    $btn.prop( 'disabled', false );
                }
            } )
            .fail( function () {
                alert( 'Network error.' );
                $btn.prop( 'disabled', false );
            } );
    } );

    // Add existing employee.
    $( document ).on( 'click', '#b2b-add-emp-btn', function () {
        var $btn  = $( this );
        var $msg  = $( '#b2b-add-emp-msg' );
        var email = $( '#b2b-add-emp-email' ).val().trim();
        var role  = $( '#b2b-add-emp-role' ).val();

        if ( ! email ) {
            teamMsg( $msg, 'Please enter an email address.', true );
            return;
        }

        $btn.prop( 'disabled', true );

        $.post( ajax_url, {
            action: 'b2b_frontend_add_employee',
            email:  email,
            role:   role,
            _nonce: teamNonce,
        } )
            .done( function ( response ) {
                if ( response.success ) {
                    appendTeamRow( response.data );
                    $( '#b2b-add-emp-email' ).val( '' );
                    teamMsg( $msg, response.data.message, false );
                } else {
                    teamMsg( $msg, response.data.message || 'Error.', true );
                }
            } )
            .fail( function () { teamMsg( $msg, 'Network error.', true ); } )
            .always( function () { $btn.prop( 'disabled', false ); } );
    } );

    // Create new employee.
    $( document ).on( 'click', '#b2b-create-emp-btn', function () {
        var $btn     = $( this );
        var $msg     = $( '#b2b-create-emp-msg' );
        var email    = $( '#b2b-new-emp-email' ).val().trim();
        var sendPass = $( '#b2b-new-emp-send-pass' ).is( ':checked' ) ? '1' : '';

        if ( ! email ) {
            teamMsg( $msg, 'Email address is required.', true );
            return;
        }

        $btn.prop( 'disabled', true );

        $.post( ajax_url, {
            action:        'b2b_frontend_create_employee',
            first_name:    $( '#b2b-new-emp-first' ).val().trim(),
            last_name:     $( '#b2b-new-emp-last' ).val().trim(),
            email:         email,
            role:          $( '#b2b-new-emp-role' ).val(),
            send_password: sendPass,
            _nonce:        teamNonce,
        } )
            .done( function ( response ) {
                if ( response.success ) {
                    appendTeamRow( response.data );
                    $( '#b2b-new-emp-first, #b2b-new-emp-last, #b2b-new-emp-email' ).val( '' );
                    teamMsg( $msg, response.data.message, false );
                } else {
                    teamMsg( $msg, response.data.message || 'Error.', true );
                }
            } )
            .fail( function () { teamMsg( $msg, 'Network error.', true ); } )
            .always( function () { $btn.prop( 'disabled', false ); } );
    } );

} )( jQuery );
