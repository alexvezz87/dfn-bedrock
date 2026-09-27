<?php
/**
 * Lost password form override for DFN Theme
 *
 * @package DFN_Theme
 * @version 2.1.0
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_lost_password_form');
?>

<div class="dfn-auth-card dfn-lost-password-card">
    <div class="dfn-card-header">
        <div class="dfn-card-icon-badge">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <h2 class="dfn-card-title"><?php esc_html_e('Recupero Password', 'dfn-theme'); ?></h2>
        <p class="dfn-card-subtitle">
            <?php echo apply_filters('woocommerce_lost_password_message', esc_html__('Hai perso la password? Inserisci il tuo nome utente o l\'indirizzo email. Riceverai tramite email un link per generarne una nuova.', 'woocommerce')); ?>
        </p>
    </div>

    <form method="post" class="woocommerce-ResetPassword lost_reset_password dfn-auth-form">
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="user_login"><?php esc_html_e('Nome utente o indirizzo email', 'woocommerce'); ?> <span class="required">*</span></label>
            <input class="woocommerce-Input woocommerce-Input--text input-text" type="text" name="user_login" id="user_login" autocomplete="username" placeholder="es. mario.rossi@email.it" required />
        </p>

        <div class="clear"></div>

        <?php do_action('woocommerce_lostpassword_form'); ?>

        <p class="woocommerce-form-row form-row" style="margin-top: 20px;">
            <input type="hidden" name="wc_reset_password" value="true" />
            <button type="submit" class="woocommerce-Button button dfn-btn-primary" value="<?php esc_attr_e('Invia link di recupero', 'dfn-theme'); ?>">
                <?php esc_html_e('Invia link di recupero', 'dfn-theme'); ?> &rarr;
            </button>
        </p>

        <div class="dfn-card-footer-links">
            <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="dfn-back-to-login">
                &larr; <?php esc_html_e('Torna ad Accedi / Registrati', 'dfn-theme'); ?>
            </a>
        </div>

        <?php wp_nonce_field('lost_password', 'woocommerce-lost-password-nonce'); ?>
    </form>
</div>

<?php
do_action('woocommerce_after_lost_password_form');
