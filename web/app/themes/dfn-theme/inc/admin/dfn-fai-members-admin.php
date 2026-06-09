<?php
/**
 * DFN Booking System 2.0 — FAI Members Administration Panel
 *
 * Fornisce un'interfaccia elegante e moderna per gestire l'anagrafica
 * dei soci FAI (tabella custom dfn_fai_members) con filtri e creazione guidata.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'dfn_fai_members_register_menu' );

/**
 * Registra il sottomenu dell'Anagrafica Soci FAI.
 */
function dfn_fai_members_register_menu(): void {
    add_submenu_page(
        'dfn-events',
        esc_html__( 'Anagrafica Soci FAI', 'dfn-theme' ),
        esc_html__( 'Soci FAI', 'dfn-theme' ),
        'dfn_manage_events',
        'dfn-fai-members',
        'dfn_render_fai_members_page'
    );
}

/**
 * Renderizza la pagina di gestione anagrafica dei Soci FAI.
 */
function dfn_render_fai_members_page(): void {
    if ( ! current_user_can( 'dfn_manage_events' ) ) {
        wp_die( esc_html__( 'Non hai i permessi per accedere a questa pagina.', 'dfn-theme' ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    // Gestione Azioni via GET (es: Eliminazione rapida securizzata tramite Nonce)
    $message = '';
    $message_type = 'success';

    if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['member_id'] ) ) {
        $member_id = intval( $_GET['member_id'] );
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'dfn_del_fai_' . $member_id ) ) {
            $wpdb->delete( $table, array( 'id' => $member_id ), array( '%d' ) );
            $message = esc_html__( 'Socio FAI rimosso con successo.', 'dfn-theme' );
        } else {
            $message = esc_html__( 'Errore di sicurezza: verifica fallita.', 'dfn-theme' );
            $message_type = 'error';
        }
    }

    // AZIONE RAPIDA: Approva Tessera FAI da verificare
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'approve' && isset( $_GET['member_id'] ) ) {
        $member_id = intval( $_GET['member_id'] );
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'dfn_approve_fai_' . $member_id ) ) {
            $wpdb->update(
                $table,
                array(
                    'verified'    => 1,
                    'verified_by' => get_current_user_id(),
                    'verified_at' => current_time( 'mysql' ),
                ),
                array( 'id' => $member_id ),
                array( '%d', '%d', '%s' ),
                array( '%d' )
            );
            
            // Invia notifica email di approvazione
            /** @var \stdClass|null $m */
            $m = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $member_id ) );
            if ( $m && ! empty( $m->email ) ) {
                dfn_send_fai_card_approved_email( $m->email, $m->first_name, $m->last_name, $m->card_number );
            }
            
            $message = esc_html__( 'Socio FAI approvato e notificato con successo.', 'dfn-theme' );
        } else {
            $message = esc_html__( 'Errore di sicurezza: verifica fallita.', 'dfn-theme' );
            $message_type = 'error';
        }
    }

    // AZIONE RAPIDA: Sottomissione Rifiuto Tessera FAI
    if ( isset( $_POST['dfn_reject_fai_submit'] ) ) {
        if ( isset( $_POST['dfn_reject_nonce'] ) && wp_verify_nonce( $_POST['dfn_reject_nonce'], 'dfn_reject_fai_action' ) ) {
            $member_id = intval( $_POST['member_id'] );
            $reason    = sanitize_text_field( $_POST['reject_reason'] );
            
            /** @var \stdClass|null $m */
            $m = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $member_id ) );
            if ( $m ) {
                // Invia notifica email di rifiuto se l'email esiste
                if ( ! empty( $m->email ) ) {
                    dfn_send_fai_card_rejected_email( $m->email, $m->first_name, $m->last_name, $m->card_number, $reason );
                }
                
                // Rimuovi la tessera non valida dal database
                $wpdb->delete( $table, array( 'id' => $member_id ), array( '%d' ) );
                
                $message = esc_html__( 'Tessera FAI rifiutata e notifica inviata all\'utente.', 'dfn-theme' );
            } else {
                $message = esc_html__( 'Tessera FAI non trovata.', 'dfn-theme' );
                $message_type = 'error';
            }
        }
    }

    // Gestione Form d'Aggiunta/Modifica manuale diretto (semplificato)
    if ( isset( $_POST['dfn_save_fai_member_submit'] ) ) {
        if ( isset( $_POST['dfn_fai_form_nonce'] ) && wp_verify_nonce( $_POST['dfn_fai_form_nonce'], 'dfn_save_fai_member_action' ) ) {
            $id          = intval( $_POST['member_id'] );
            $first_name  = sanitize_text_field( $_POST['first_name'] );
            $last_name   = sanitize_text_field( $_POST['last_name'] );
            $email       = ! empty( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : null;
            $phone       = sanitize_text_field( $_POST['phone'] );
            $card_number = sanitize_text_field( $_POST['card_number'] );
            $card_expiry = ! empty( $_POST['card_expiry'] ) ? sanitize_text_field( $_POST['card_expiry'] ) : null;

            if ( ! empty( $first_name ) && ! empty( $last_name ) && ! empty( $card_number ) ) {
                $old_member = null;
                if ( $id > 0 ) {
                    $old_member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
                }

                $data = array(
                    'first_name'  => $first_name,
                    'last_name'   => $last_name,
                    'email'       => $email,
                    'phone'       => ! empty( $phone ) ? $phone : null,
                    'card_number' => $card_number,
                    'card_expiry' => $card_expiry,
                    'verified'    => 1,
                    'verified_by' => get_current_user_id(),
                    'verified_at' => current_time( 'mysql' ),
                );
                $formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' );

                if ( $id > 0 ) {
                    $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
                    $message = esc_html__( 'Socio FAI aggiornato correttamente.', 'dfn-theme' );
                    
                    // Se era da verificare e ora viene salvato (quindi verificato)
                    if ( $old_member && intval( $old_member->verified ) === 0 && ! empty( $email ) ) {
                        dfn_send_fai_card_approved_email( $email, $first_name, $last_name, $card_number );
                    }
                } else {
                    $wpdb->insert( $table, $data, $formats );
                    $message = esc_html__( 'Nuovo socio FAI registrato correttamente.', 'dfn-theme' );
                }
            } else {
                $message = esc_html__( 'Tutti i campi obbligatori devono essere compilati.', 'dfn-theme' );
                $message_type = 'error';
            }
        }
    }

    // Modalità Modifica o Rifiuto: carica i dati se richiesto
    $edit_member = null;
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && isset( $_GET['member_id'] ) ) {
        $member_id = intval( $_GET['member_id'] );
        $edit_member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $member_id ) );
    }

    $reject_member = null;
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'reject' && isset( $_GET['member_id'] ) ) {
        $member_id = intval( $_GET['member_id'] );
        $reject_member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $member_id ) );
    }

    // Filtro e ricerca in tempo reale
    $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
    if ( ! empty( $search ) ) {
        $search_query = '%' . $wpdb->esc_like( $search ) . '%';
        $members = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE first_name LIKE %s 
                OR last_name LIKE %s 
                OR email LIKE %s 
                OR card_number LIKE %s 
             ORDER BY last_name ASC, first_name ASC",
            $search_query, $search_query, $search_query, $search_query
        ) );
    } else {
        $members = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY last_name ASC, first_name ASC LIMIT 100" );
    }

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 25px;">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-id"></span>
                <h1><?php esc_html_e( 'Anagrafica Soci FAI', 'dfn-theme' ); ?></h1>
            </div>
        </header>

        <?php if ( ! empty( $message ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
        <?php endif; ?>

        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
            <!-- Form d'Inserimento / Modifica / Rifiuto (Colonna Sinistra) -->
            <div style="flex: 1 1 350px; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; height: fit-content;">
                <?php if ( $reject_member ) : ?>
                    <h3 style="margin-top: 0; color: #b91c1c; font-weight: 800; font-size: 18px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                        <?php esc_html_e( 'Rifiuta Tessera FAI', 'dfn-theme' ); ?>
                    </h3>
                    <form method="POST" style="margin-top: 15px;">
                        <?php wp_nonce_field( 'dfn_reject_fai_action', 'dfn_reject_nonce' ); ?>
                        <input type="hidden" name="member_id" value="<?php echo intval( $reject_member->id ); ?>">
                        
                        <p style="font-size: 13px; line-height: 1.4; color: #334155;">
                            Stai rifiutando la tessera n° <code><?php echo esc_html( $reject_member->card_number ); ?></code> inserita da <strong><?php echo esc_html( $reject_member->first_name . ' ' . $reject_member->last_name ); ?></strong> (<?php echo esc_html( $reject_member->email ); ?>).
                        </p>
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e( 'Motivazione del Rifiuto *', 'dfn-theme' ); ?></label>
                            <textarea name="reject_reason" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;" rows="4" placeholder="<?php esc_attr_e( 'Es: Tessera scaduta il 12/2025, intestatario non corrispondente, codice inesistente...', 'dfn-theme' ); ?>"></textarea>
                        </div>
                        
                        <button type="submit" name="dfn_reject_fai_submit" class="button" style="width: 100%; padding: 10px; height: auto; font-size: 14px; font-weight: 700; background: #b91c1c; color: white; border: none; border-radius: 6px; cursor: pointer;"><?php esc_html_e( 'Invia Notifica e Rifiuta', 'dfn-theme' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-fai-members' ) ); ?>" class="button" style="width: 100%; text-align: center; margin-top: 10px; box-sizing: border-box; padding: 6px;"><?php esc_html_e( 'Annulla', 'dfn-theme' ); ?></a>
                    </form>
                <?php else : ?>
                    <h3 style="margin-top: 0; color: #004b23; font-weight: 800; font-size: 18px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                        <?php echo $edit_member ? esc_html__( 'Modifica Socio FAI', 'dfn-theme' ) : esc_html__( 'Aggiungi Nuovo Socio', 'dfn-theme' ); ?>
                    </h3>
                    
                    <form method="POST" style="margin-top: 15px;">
                        <?php wp_nonce_field( 'dfn_save_fai_member_action', 'dfn_fai_form_nonce' ); ?>
                        <input type="hidden" name="member_id" value="<?php echo $edit_member ? intval( $edit_member->id ) : 0; ?>">
 
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e( 'Nome *', 'dfn-theme' ); ?></label>
                            <input type="text" name="first_name" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr( $edit_member->first_name ) : ''; ?>">
                        </div>
 
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e( 'Cognome *', 'dfn-theme' ); ?></label>
                            <input type="text" name="last_name" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr( $edit_member->last_name ) : ''; ?>">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e( 'Email', 'dfn-theme' ); ?></label>
                            <input type="email" name="email" style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr( $edit_member->email ) : ''; ?>">
                        </div>
 
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e( 'Telefono', 'dfn-theme' ); ?></label>
                            <input type="text" name="phone" style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr( $edit_member->phone ) : ''; ?>">
                        </div>
 
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e( 'Numero Tessera *', 'dfn-theme' ); ?></label>
                            <input type="text" name="card_number" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr( $edit_member->card_number ) : ''; ?>">
                        </div>
 
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e( 'Scadenza Tessera', 'dfn-theme' ); ?></label>
                            <input type="date" name="card_expiry" style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr( $edit_member->card_expiry ) : ''; ?>">
                        </div>
 
                        <button type="submit" name="dfn_save_fai_member_submit" class="button button-primary" style="width: 100%; padding: 10px; height: auto; font-size: 14px; font-weight: 700; background: #004b23; border: none; border-radius: 6px; cursor: pointer;"><?php echo $edit_member ? esc_html__( 'Salva Modifiche', 'dfn-theme' ) : esc_html__( 'Aggiungi Socio', 'dfn-theme' ); ?></button>
                        <?php if ( $edit_member ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-fai-members' ) ); ?>" class="button" style="width: 100%; text-align: center; margin-top: 10px; box-sizing: border-box; padding: 6px;"><?php esc_html_e( 'Annulla Modifica', 'dfn-theme' ); ?></a>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
 
            <!-- Tabella dei Soci Registrati (Colonna Destra) -->
            <div style="flex: 2 1 600px; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #f1f5f9;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: #004b23; font-weight: 800; font-size: 18px;"><?php esc_html_e( 'Soci FAI Registrati', 'dfn-theme' ); ?></h3>
                    
                    <form method="GET" style="display: flex; gap: 8px;">
                        <input type="hidden" name="page" value="dfn-fai-members">
                        <input type="text" name="s" placeholder="<?php esc_attr_e( 'Cerca socio...', 'dfn-theme' ); ?>" value="<?php echo esc_attr( $search ); ?>" style="padding: 5px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;">
                        <button type="submit" class="button" style="font-weight: 700;"><?php esc_html_e( 'Cerca', 'dfn-theme' ); ?></button>
                    </form>
                </div>
 
                <table class="wp-list-table widefat fixed striped dfn-events-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Cognome & Nome', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Numero Tessera', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Scadenza Tessera', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Stato', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Azioni', 'dfn-theme' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $members ) ) : ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px; color: #94a3b8;"><?php esc_html_e( 'Nessun socio FAI registrato o corrispondente alla ricerca.', 'dfn-theme' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $members as $m ) : 
                                $is_expired   = ! empty( $m->card_expiry ) && strtotime( $m->card_expiry ) < time();
                                $is_verified  = intval( $m->verified ) === 1;
                                
                                if ( ! $is_verified ) {
                                    $status_class = 'dfn-status-draft';
                                    $status_label = esc_html__( 'Da verificare', 'dfn-theme' );
                                } elseif ( empty( $m->card_expiry ) ) {
                                    $status_class = 'dfn-status-draft';
                                    $status_label = esc_html__( 'Senza scadenza', 'dfn-theme' );
                                } elseif ( $is_expired ) {
                                    $status_class = 'dfn-status-draft';
                                    $status_label = esc_html__( 'Scaduta', 'dfn-theme' );
                                } else {
                                    $status_class = 'dfn-status-published';
                                    $status_label = esc_html__( 'Attiva', 'dfn-theme' );
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $m->last_name . ' ' . $m->first_name ); ?></strong></td>
                                    <td><?php echo esc_html( $m->email ?: '' ); ?></td>
                                    <td><code><?php echo esc_html( $m->card_number ); ?></code></td>
                                    <td><strong><?php echo ! empty( $m->card_expiry ) ? date_i18n( 'd M Y', strtotime( $m->card_expiry ) ) : esc_html__( 'Da definire', 'dfn-theme' ); ?></strong></td>
                                    <td>
                                        <span class="dfn-badge <?php echo esc_attr( $status_class ); ?>" style="<?php if ( ! $is_verified ) { echo 'background: #fff3cd; color: #856404; border: 1px solid #ffeeba;'; } ?>"><?php echo esc_html( $status_label ); ?></span>
                                    </td>
                                    <td>
                                        <?php if ( ! $is_verified ) : ?>
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dfn-fai-members&action=approve&member_id=' . $m->id ), 'dfn_approve_fai_' . $m->id ) ); ?>" class="button button-small" style="background:#dcfce7; border-color:#bbf7d0; color:#166534; font-weight:bold;" title="<?php esc_attr_e( 'Applica sconto e approva', 'dfn-theme' ); ?>">✅ Approva</a>
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-fai-members&action=reject&member_id=' . $m->id ) ); ?>" class="button button-small" style="background:#fee2e2; border-color:#fecaca; color:#991b1b; font-weight:bold;" title="<?php esc_attr_e( 'Rifiuta e spiega motivo', 'dfn-theme' ); ?>">❌ Rifiuta</a>
                                        <?php else: ?>
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-fai-members&action=edit&member_id=' . $m->id ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Modifica dati socio', 'dfn-theme' ); ?>">✏️</a>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dfn-fai-members&action=delete&member_id=' . $m->id ), 'dfn_del_fai_' . $m->id ) ); ?>" class="button button-small dfn-btn-delete" title="<?php esc_attr_e( 'Elimina socio permanentemente', 'dfn-theme' ); ?>" onclick="return confirm('Sei sicuro di voler eliminare questo socio?');">🗑️</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}
