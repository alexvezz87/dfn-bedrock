<?php
/**
 * Reset password form override for DFN Theme
 *
 * @package DFN_Theme
 * @version 2.1.0
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_reset_password_form');
?>

<div class="dfn-auth-card dfn-reset-password-card">
    <div class="dfn-card-header">
        <div class="dfn-card-icon-badge">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
            </svg>
        </div>
        <h2 class="dfn-card-title"><?php esc_html_e('Imposta Nuova Password', 'dfn-theme'); ?></h2>
        <p class="dfn-card-subtitle">
            <?php echo apply_filters('woocommerce_reset_password_message', esc_html__('Inserisci e conferma una nuova password sicura per il tuo account.', 'dfn-theme')); ?>
        </p>
    </div>

    <form method="post" class="woocommerce-ResetPassword lost_reset_password dfn-auth-form">
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="password_1"><?php esc_html_e('Nuova password', 'woocommerce'); ?> <span class="required">*</span></label>
            <span class="password-input">
                <input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_1" id="password_1" autocomplete="new-password" placeholder="Almeno 8 caratteri" required />
                <span class="show-password-input" title="<?php esc_attr_e('Mostra/Nascondi password', 'dfn-theme'); ?>"></span>
            </span>
        </p>

        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="password_2"><?php esc_html_e('Reinserisci la nuova password', 'woocommerce'); ?> <span class="required">*</span></label>
            <span class="password-input">
                <input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_2" id="password_2" autocomplete="new-password" placeholder="Ripeti la nuova password" required />
                <span class="show-password-input" title="<?php esc_attr_e('Mostra/Nascondi password', 'dfn-theme'); ?>"></span>
            </span>
        </p>

        <?php
        $reset_key   = isset($args['key']) ? $args['key'] : ($_GET['key'] ?? '');
        $reset_login = isset($args['login']) ? $args['login'] : ($_GET['login'] ?? '');
        ?>
        <input type="hidden" name="reset_key" value="<?php echo esc_attr($reset_key); ?>" />
        <input type="hidden" name="reset_login" value="<?php echo esc_attr($reset_login); ?>" />

        <div class="clear"></div>

        <?php do_action('woocommerce_resetpassword_form'); ?>

        <p class="woocommerce-form-row form-row" style="margin-top: 20px;">
            <input type="hidden" name="wc_reset_password" value="true" />
            <button type="submit" class="woocommerce-Button button dfn-btn-primary" value="<?php esc_attr_e('Salva nuova password', 'dfn-theme'); ?>">
                <?php esc_html_e('Salva nuova password', 'dfn-theme'); ?> &rarr;
            </button>
        </p>

        <div class="dfn-card-footer-links">
            <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="dfn-back-to-login">
                &larr; <?php esc_html_e('Torna ad Accedi / Registrati', 'dfn-theme'); ?>
            </a>
        </div>

        <?php wp_nonce_field('reset_password', 'woocommerce-reset-password-nonce'); ?>
    </form>
</div>

<?php
do_action('woocommerce_after_reset_password_form');
