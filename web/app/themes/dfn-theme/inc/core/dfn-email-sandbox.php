<?php
/**
 * DFN Booking System 2.0.1 — Global Email Sandbox & Safety Net
 *
 * Intercetta a livello GLOBALE dell'intero sito WordPress tutte le email in uscita (wp_mail),
 * impedendo invii accidentali a clienti o visitatori reali durante le attività di test,
 * collaudo in Staging o configurazione.
 *
 * Modalità disponibili:
 * - 'disabled': Salvagente disattivato, invio normale ai destinatari originali.
 * - 'redirect': Tutte le email in uscita dal sito vengono deviate a un unico indirizzo di test,
 *               azzerando Cc e Bcc ed evidenziando nell'oggetto e nel corpo il destinatario originale.
 * - 'mute':     Tutte le email in uscita vengono intercettate e bloccate (nessun invio SMTP),
 *               simulando successo per l'applicazione e registrando l'evento nei log.
 *
 * @package DFN_Theme
 * @since   2.0.1
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * 1. Filtro PRE_WP_MAIL (Priorità 99999):
 * Intercetta l'invio prima che PHPMailer venga invocato. Se in modalità 'mute', blocca la spedizione.
 *
 * @param null|bool $return Valore di ritorno di default (null per procedere).
 * @param array     $atts   Argomenti della funzione wp_mail().
 * @return null|bool True se bloccata con successo, null altrimenti.
 */
function dfn_global_email_sandbox_pre_filter($return, array $atts)
{
    $mode = function_exists('dfn_get_setting') ? dfn_get_setting('email_sandbox_mode', 'disabled') : 'disabled';

    if ($mode === 'mute') {
        $raw_to = $atts['to'] ?? '';
        $to_str = is_array($raw_to) ? implode(', ', $raw_to) : (string) $raw_to;
        $subject = (string) ($atts['subject'] ?? '');

        if (function_exists('dfn_log_write')) {
            dfn_log_write(
                'email',
                'Salvagente Globale',
                sprintf('[SALVAGENTE MUTE] Email bloccata a: %s | Oggetto: %s', $to_str, $subject),
                'success'
            );
        }

        // Ritorna true per simulare successo a livello di wp_mail() ed evitare errori nei plugin chiamanti
        return true;
    }

    return $return;
}
add_filter('pre_wp_mail', 'dfn_global_email_sandbox_pre_filter', 99999, 2);

/**
 * 2. Filtro WP_MAIL (Priorità 99999):
 * Modifica i destinatari, le intestazioni (rimuovendo Cc e Bcc) e il corpo del messaggio in modalità 'redirect'.
 *
 * @param array $atts Parametri di wp_mail(): ['to', 'subject', 'message', 'headers', 'attachments'].
 * @return array
 */
function dfn_global_email_sandbox_filter(array $atts): array
{
    $mode = function_exists('dfn_get_setting') ? dfn_get_setting('email_sandbox_mode', 'disabled') : 'disabled';

    if ($mode !== 'redirect') {
        return $atts;
    }

    $default_admin = get_option('admin_email');
    $redirect_to = function_exists('dfn_get_setting') ? dfn_get_setting('email_sandbox_redirect_to', $default_admin) : $default_admin;

    if (empty($redirect_to) || ! is_email($redirect_to)) {
        $redirect_to = $default_admin;
    }

    // Ricava la lista dei destinatari originali
    $raw_to = $atts['to'] ?? '';
    $original_to = is_array($raw_to) ? implode(', ', $raw_to) : (string) $raw_to;

    // Rimuove completamente tutte le intestazioni Cc e Bcc per eliminare copie a clienti reali
    $cleaned_headers = [];
    $raw_headers = $atts['headers'] ?? [];
    if (! is_array($raw_headers)) {
        $raw_headers = explode("\n", str_replace("\r\n", "\n", (string) $raw_headers));
    }

    foreach ($raw_headers as $header_line) {
        $header_line = trim($header_line);
        if (empty($header_line)) {
            continue;
        }
        // Se la riga inizia per 'cc:' o 'bcc:' (case-insensitive), la escludiamo tassativamente
        if (preg_match('/^(cc|bcc)\s*:/i', $header_line)) {
            continue;
        }
        $cleaned_headers[] = $header_line;
    }

    $atts['headers'] = $cleaned_headers;

    // Sostituisce il destinatario principale con l'indirizzo di test
    $atts['to'] = $redirect_to;

    // Antepone all'oggetto l'indicatore Sandbox con il destinatario originale
    $atts['subject'] = sprintf('[SANDBOX TEST ➔ Originale: %s] %s', $original_to, $atts['subject'] ?? '');

    // Inietta un banner informativo in cima al corpo dell'email
    $message = (string) ($atts['message'] ?? '');
    $is_html = (strpos($message, '<html') !== false || strpos($message, '<body') !== false || strpos($message, '</div>') !== false || strpos($message, '</p>') !== false);

    if ($is_html) {
        $banner = '<div style="background-color: #fef3c7; border: 2px solid #f59e0b; color: #92400e; padding: 12px 16px; margin: 15px auto; max-width: 600px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 13px; border-radius: 6px; line-height: 1.5; box-sizing: border-box;">'
            . '<strong>⚠️ AVVISO SALVAGENTE EMAIL (DFN SANDBOX GLOBALE):</strong><br>'
            . 'Questa email era originariamente destinata a: <strong>' . esc_html($original_to) . '</strong>.<br>'
            . 'È stata reindirizzata a questa casella di collaudo (<code>' . esc_html($redirect_to) . '</code>) per proteggere i destinatari reali durante i test.'
            . '</div>';

        if (preg_match('/<body[^>]*>/i', $message, $matches)) {
            $message = str_replace($matches[0], $matches[0] . $banner, $message);
        } else {
            $message = $banner . $message;
        }
    } else {
        $banner = "=== [AVVISO SALVAGENTE EMAIL - DFN SANDBOX GLOBALE] ===\n"
            . "Destinatario originale: {$original_to}\n"
            . "Reindirizzata a questa casella di test ({$redirect_to}) per proteggere i destinatari reali.\n"
            . "========================================================\n\n";
        $message = $banner . $message;
    }

    $atts['message'] = $message;

    if (function_exists('dfn_log_write')) {
        dfn_log_write(
            'email',
            'Salvagente Globale',
            sprintf('[SALVAGENTE REDIRECT] Email deviata da %s a %s | Oggetto: %s', $original_to, $redirect_to, $atts['subject']),
            'success'
        );
    }

    return $atts;
}
add_filter('wp_mail', 'dfn_global_email_sandbox_filter', 99999, 1);

/**
 * 3. Banner di Avviso in Bacheca Amministrazione WordPress:
 * Segnala in modo chiaro ed evidente a tutti gli amministratori se il salvagente globale è attivo.
 */
function dfn_global_email_sandbox_admin_notice(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $mode = function_exists('dfn_get_setting') ? dfn_get_setting('email_sandbox_mode', 'disabled') : 'disabled';
    if ($mode === 'disabled') {
        return;
    }

    $target = function_exists('dfn_get_setting') ? dfn_get_setting('email_sandbox_redirect_to', get_option('admin_email')) : get_option('admin_email');
    $settings_url = admin_url('admin.php?page=dfn-settings&tab=email');

    if ($mode === 'redirect') {
        ?>
        <div class="notice notice-warning is-dismissible" style="border-left-color: #f59e0b; background: #fffbeb; padding: 12px 18px;">
            <p style="margin: 0; font-size: 13px; color: #92400e;">
                <strong style="color: #b45309;">🛡️ SALVAGENTE EMAIL GLOBALE ATTIVO (Modalità Redirect):</strong> 
                Tutte le email in uscita dall'intero sito sono deviate verso la casella di test <code><?php echo esc_html($target); ?></code>. 
                Nessun cliente o visitatore reale riceverà alcuna comunicazione. 
                <a href="<?php echo esc_url($settings_url); ?>" style="font-weight: 600; color: #004b23; margin-left: 8px;">Configura o Disattiva &rarr;</a>
            </p>
        </div>
        <?php
    } elseif ($mode === 'mute') {
        ?>
        <div class="notice notice-error is-dismissible" style="border-left-color: #ef4444; background: #fef2f2; padding: 12px 18px;">
            <p style="margin: 0; font-size: 13px; color: #991b1b;">
                <strong style="color: #b91c1c;">🔇 SALVAGENTE EMAIL GLOBALE ATTIVO (Modalità Mute / Silenziosa):</strong> 
                L'invio reale di TUTTE le email del sito è completamente bloccato. Le notifiche vengono simulate come riuscite e salvate nel log. 
                <a href="<?php echo esc_url($settings_url); ?>" style="font-weight: 600; color: #004b23; margin-left: 8px;">Configura o Disattiva &rarr;</a>
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'dfn_global_email_sandbox_admin_notice');
