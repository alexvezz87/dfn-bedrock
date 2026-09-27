<?php
/**
 * Customer Reset Password email - Premium FAI Layout
 *
 * @package DFN_Theme
 * @version 2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

$user = null;
if (! empty($user_id)) {
    $user = get_userdata($user_id);
}
if (! $user && ! empty($user_login)) {
    $user = get_user_by('login', $user_login);
}
if (! $user && ! empty($user_email)) {
    $user = get_user_by('email', $user_email);
}

$first_name   = $user ? $user->first_name : '';
$display_name = ! empty($first_name) ? $first_name : ($user ? $user->display_name : ($user_login ?? 'Utente'));
$email_addr   = ! empty($user_email) ? $user_email : ($user ? $user->user_email : '');
$login_name   = ! empty($user_login) ? $user_login : ($user ? $user->user_login : '');
$key_val      = $reset_key ?? '';

if (function_exists('wc_get_account_endpoint_url')) {
    $reset_url = add_query_arg([
        'key'   => $key_val,
        'id'    => $user ? $user->ID : ($user_id ?? 0),
        'login' => rawurlencode($login_name),
    ], wc_get_account_endpoint_url('lost-password'));
} else {
    $reset_url = add_query_arg([
        'key'   => $key_val,
        'id'    => $user ? $user->ID : ($user_id ?? 0),
        'login' => rawurlencode($login_name),
    ], site_url('/mio-account/lost-password/'));
}

$sender = function_exists('dfn_get_volunteer_email_sender') ? dfn_get_volunteer_email_sender() : null;
$delegation_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
$sender_name = ! empty($sender['name']) ? $sender['name'] : ('Coordinamento Volontari ' . $delegation_name);

ob_start();
?>
<p style="font-size: 16px; color: #1e293b; margin-bottom: 16px;">
    Ciao <strong><?php echo esc_html($display_name); ?></strong>,
</p>
<p style="font-size: 15px; color: #334155; line-height: 1.6; margin-bottom: 20px;">
    Abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account sulla piattaforma <strong><?php echo esc_html($delegation_name); ?></strong>.
</p>

<div class="info-box" style="background-color: #f8fafc; border-left: 4px solid #004b23; padding: 16px 20px; margin: 22px 0; border-radius: 4px;">
    <p class="info-box-title" style="font-weight: 700; font-size: 14.5px; color: #004b23; margin: 0 0 8px;">Dati Account:</p>
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 3px 0; font-size: 14px; font-weight: 600; color: #475569; width: 140px;">Nome utente:</td>
            <td style="padding: 3px 0; font-size: 14px; color: #0f172a;"><strong><?php echo esc_html($login_name); ?></strong></td>
        </tr>
        <?php if (! empty($email_addr)) : ?>
        <tr>
            <td style="padding: 3px 0; font-size: 14px; font-weight: 600; color: #475569;">Email associata:</td>
            <td style="padding: 3px 0; font-size: 14px; color: #0f172a;"><?php echo esc_html($email_addr); ?></td>
        </tr>
        <?php endif; ?>
    </table>
</div>

<p style="font-size: 15px; color: #334155; line-height: 1.6; margin-bottom: 24px;">
    Per scegliere una nuova password e riattivare l'accesso alla tua area riservata, fai clic sul pulsante seguente:
</p>

<div style="text-align: center; margin: 28px 0;">
    <a href="<?php echo esc_url($reset_url); ?>" class="button" style="display: inline-block; background-color: #004b23; color: #ffffff !important; padding: 14px 30px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 15px; border-bottom: 3px solid #002e15;">
        Reimposta la tua Password &rarr;
    </a>
</div>

<p style="font-size: 12.5px; color: #64748b; line-height: 1.5; margin-top: 24px;">
    <em>Se il pulsante non funziona, copia e incolla il seguente link nella barra degli indirizzi del tuo browser:<br>
    <a href="<?php echo esc_url($reset_url); ?>" style="color: #004b23; word-break: break-all;"><?php echo esc_html($reset_url); ?></a></em>
</p>

<div class="divider" style="height: 1px; background-color: #e2e8f0; margin: 25px 0;"></div>

<p style="font-size: 13.5px; color: #64748b; line-height: 1.5;">
    🔒 <strong>Non hai richiesto tu il ripristino?</strong><br>
    Se non hai effettuato questa richiesta, puoi ignorare questa comunicazione. La tua password attuale rimarrà valida e il tuo account è completamente al sicuro.
</p>

<p style="margin-top: 24px; font-size: 14.5px; color: #334155; line-height: 1.5;">
    Cordiali saluti,<br>
    <strong><?php echo esc_html($sender_name); ?></strong>
</p>
<?php
$inner_content = ob_get_clean();

if (function_exists('dfn_get_email_html_template')) {
    echo dfn_get_email_html_template('Richiesta di reimpostazione password', $inner_content);
} else {
    echo $inner_content;
}
