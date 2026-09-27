<?php
/**
 * Edit account form template override for DFN Theme - Accordion Layout
 *
 * @package DFN_Theme
 * @version 2.1.0
 */

defined('ABSPATH') || exit;

$user = isset($user) && $user instanceof WP_User ? $user : wp_get_current_user();

do_action('woocommerce_before_edit_account_form');
?>

<form class="woocommerce-EditAccountForm edit-account dfn-edit-account-form" action="" method="post" <?php do_action('woocommerce_edit_account_form_tag'); ?> >

	<?php do_action('woocommerce_edit_account_form_start'); ?>

    <div class="dfn-accordion-group" id="dfn-account-accordion">

        <!-- ========================================================
             1. SEZIONE INFORMAZIONI PERSONALI
             ======================================================== -->
        <div class="dfn-accordion-item is-open" data-accordion="info">
            <button type="button" class="dfn-accordion-header" aria-expanded="true">
                <div class="dfn-accordion-title-wrap">
                    <span class="dfn-accordion-icon">👤</span>
                    <div class="dfn-accordion-text">
                        <h3 class="dfn-accordion-title"><?php esc_html_e('Informazioni Personali', 'dfn-theme'); ?></h3>
                        <p class="dfn-accordion-subtitle"><?php esc_html_e('Nome, cognome, nome visualizzato ed email del tuo account', 'dfn-theme'); ?></p>
                    </div>
                </div>
                <span class="dfn-accordion-chevron">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </span>
            </button>
            <div class="dfn-accordion-collapse" style="display: block;">
                <div class="dfn-accordion-body">
                    <div class="dfn-form-grid-2">
                        <div class="dfn-field-group">
                            <label for="account_first_name"><?php esc_html_e('Nome', 'woocommerce'); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
                            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr($user->first_name); ?>" aria-required="true" />
                        </div>
                        <div class="dfn-field-group">
                            <label for="account_last_name"><?php esc_html_e('Cognome', 'woocommerce'); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
                            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr($user->last_name); ?>" aria-required="true" />
                        </div>
                    </div>

                    <div class="dfn-field-group">
                        <label for="account_display_name"><?php esc_html_e('Nome visualizzato', 'woocommerce'); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_display_name" id="account_display_name" aria-describedby="account_display_name_description" value="<?php echo esc_attr($user->display_name); ?>" aria-required="true" />
                        <span id="account_display_name_description" class="dfn-field-hint"><em><?php echo wc_reviews_enabled() ? esc_html__('Questo sarà il nome visualizzato nella sezione account e nelle recensioni.', 'woocommerce') : esc_html__('Questo sarà il nome visualizzato nella sezione account.', 'woocommerce'); ?></em></span>
                    </div>

                    <div class="dfn-field-group" style="margin-bottom: 0 !important;">
                        <label for="account_email"><?php esc_html_e('Indirizzo Email', 'woocommerce'); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
                        <input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr($user->user_email); ?>" aria-required="true" />
                    </div>

                    <?php do_action('woocommerce_edit_account_form_fields'); ?>
                </div>
            </div>
        </div>

        <!-- ========================================================
             2. SEZIONE SICUREZZA E CAMBIO PASSWORD
             ======================================================== -->
        <div class="dfn-accordion-item" data-accordion="password">
            <button type="button" class="dfn-accordion-header" aria-expanded="false">
                <div class="dfn-accordion-title-wrap">
                    <span class="dfn-accordion-icon">🔒</span>
                    <div class="dfn-accordion-text">
                        <h3 class="dfn-accordion-title"><?php esc_html_e('Sicurezza & Cambio Password', 'dfn-theme'); ?></h3>
                        <p class="dfn-accordion-subtitle"><?php esc_html_e('Modifica la password di accesso (lascia i campi vuoti se non desideri cambiarla)', 'dfn-theme'); ?></p>
                    </div>
                </div>
                <span class="dfn-accordion-chevron">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </span>
            </button>
            <div class="dfn-accordion-collapse" style="display: none;">
                <div class="dfn-accordion-body">
                    <div class="dfn-field-group">
                        <label for="password_current"><?php esc_html_e('Password attuale (lascia vuoto per non modificare)', 'woocommerce'); ?></label>
                        <div class="dfn-password-input-wrap">
                            <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_current" id="password_current" autocomplete="current-password" placeholder="<?php esc_attr_e('Password attuale', 'dfn-theme'); ?>" />
                            <button type="button" class="dfn-pwd-toggle-btn" aria-label="<?php esc_attr_e('Mostra password', 'dfn-theme'); ?>" tabindex="-1">
                                <svg class="icon-eye-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                <svg class="icon-eye-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                            </button>
                        </div>
                    </div>

                    <div class="dfn-form-grid-2" style="margin-bottom: 0 !important;">
                        <div class="dfn-field-group">
                            <label for="password_1"><?php esc_html_e('Nuova password (lascia vuoto per non modificare)', 'woocommerce'); ?></label>
                            <div class="dfn-password-input-wrap">
                                <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_1" id="password_1" autocomplete="new-password" placeholder="<?php esc_attr_e('Nuova password', 'dfn-theme'); ?>" />
                                <button type="button" class="dfn-pwd-toggle-btn" aria-label="<?php esc_attr_e('Mostra password', 'dfn-theme'); ?>" tabindex="-1">
                                    <svg class="icon-eye-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg class="icon-eye-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                                </button>
                            </div>
                        </div>
                        <div class="dfn-field-group">
                            <label for="password_2"><?php esc_html_e('Conferma nuova password', 'woocommerce'); ?></label>
                            <div class="dfn-password-input-wrap">
                                <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_2" id="password_2" autocomplete="new-password" placeholder="<?php esc_attr_e('Ripeti nuova password', 'dfn-theme'); ?>" />
                                <button type="button" class="dfn-pwd-toggle-btn" aria-label="<?php esc_attr_e('Mostra password', 'dfn-theme'); ?>" tabindex="-1">
                                    <svg class="icon-eye-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg class="icon-eye-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================
             3. SEZIONE PREFERENZE DI NOTIFICA
             ======================================================== -->
        <?php
        $current_user_id = get_current_user_id();
        $is_volunteer    = function_exists('dfn_is_user_volunteer') && dfn_is_user_volunteer($current_user_id);
        $notify_title    = $is_volunteer ? __('Preferenze di Notifica Volontario', 'dfn-theme') : __('Preferenze di Notifica Email', 'dfn-theme');
        $notify_sub      = $is_volunteer 
            ? __('Gestisci le comunicazioni operative e i promemoria automatici via email dalla Delegazione FAI', 'dfn-theme')
            : __('Personalizza le comunicazioni e i promemoria automatici che desideri ricevere via email dal FAI Novara', 'dfn-theme');
        ?>
        <div class="dfn-accordion-item" data-accordion="notifications">
            <button type="button" class="dfn-accordion-header" aria-expanded="false">
                <div class="dfn-accordion-title-wrap">
                    <span class="dfn-accordion-icon">🔔</span>
                    <div class="dfn-accordion-text">
                        <h3 class="dfn-accordion-title"><?php echo esc_html($notify_title); ?></h3>
                        <p class="dfn-accordion-subtitle"><?php echo esc_html($notify_sub); ?></p>
                    </div>
                </div>
                <span class="dfn-accordion-chevron">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </span>
            </button>
            <div class="dfn-accordion-collapse" style="display: none;">
                <div class="dfn-accordion-body">
                    <?php
                    if (function_exists('dfn_render_edit_account_notification_fields')) {
                        dfn_render_edit_account_notification_fields($current_user_id, $is_volunteer);
                    }
                    do_action('woocommerce_edit_account_form');
                    ?>
                </div>
            </div>
        </div>

    </div><!-- /.dfn-accordion-group -->

	<div class="dfn-account-form-actions">
		<?php wp_nonce_field('save_account_details', 'save-account-details-nonce'); ?>
		<button type="submit" class="woocommerce-Button button dfn-btn-save-account" name="save_account_details" value="<?php esc_attr_e('Salva modifiche', 'woocommerce'); ?>">
            💾 <?php esc_html_e('Salva modifiche', 'woocommerce'); ?>
        </button>
		<input type="hidden" name="action" value="save_account_details" />
	</div>

	<?php do_action('woocommerce_edit_account_form_end'); ?>
</form>

<?php do_action('woocommerce_after_edit_account_form'); ?>
