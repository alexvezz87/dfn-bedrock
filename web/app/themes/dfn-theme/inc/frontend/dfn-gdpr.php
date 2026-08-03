<?php

/**
 * DFN GDPR Compliance — Modulo Centralizzato
 *
 * Fornisce:
 * 1. Helper PHP per generare la checkbox informativa sulla privacy nei form
 * 2. Cookie consent banner con categorie (tecnici, analitici)
 * 3. Enqueue di dfn-gdpr.css e dfn-gdpr.js
 * 4. Integrazione con il checkout WooCommerce (checkbox + validazione server-side)
 * 5. Creazione automatica della pagina Privacy Policy se non esiste
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ============================================================
 * CONFIGURAZIONE
 * Personalizza qui gli URL e i dettagli del titolare.
 * ============================================================
 */

/** URL della pagina Privacy Policy sul sito */
if (! defined('DFN_PRIVACY_POLICY_URL')) {
    $pp_page_id = (int) get_option('wp_page_for_privacy_policy');
    define('DFN_PRIVACY_POLICY_URL', $pp_page_id > 0 ? get_permalink($pp_page_id) : home_url('/privacy-policy/'));
}

/** Nome del titolare del trattamento */
if (! defined('DFN_PRIVACY_OWNER_NAME')) {
    define('DFN_PRIVACY_OWNER_NAME', get_bloginfo('name'));
}

/**
 * ID di misura Google Analytics 4 (es. G-XXXXXXXXXX).
 * Lasciare vuoto per disabilitare il caricamento di GA4.
 * Modificabile anche da Impostazioni → DFN Settings se integrato.
 */
if (! defined('DFN_GA4_MEASUREMENT_ID')) {
    define('DFN_GA4_MEASUREMENT_ID', ''); // ← Inserisci qui il tuo ID GA4 es: G-XXXXXXXXXX
}


/**
 * ============================================================
 * 1. ENQUEUE CSS E JS
 * ============================================================
 */

/**
 * Registra e carica i file CSS/JS del modulo GDPR.
 *
 * @return void
 */
function dfn_gdpr_enqueue_assets(): void
{
    $css_path = get_stylesheet_directory() . '/assets/css/dfn-gdpr.css';
    $js_path  = get_stylesheet_directory() . '/assets/js/dfn-gdpr.js';

    wp_enqueue_style(
        'dfn-gdpr-css',
        trailingslashit(get_stylesheet_directory_uri()) . 'assets/css/dfn-gdpr.css',
        [],
        file_exists($css_path) ? (string) filemtime($css_path) : '2.1.0'
    );

    wp_enqueue_script(
        'dfn-gdpr-js',
        trailingslashit(get_stylesheet_directory_uri()) . 'assets/js/dfn-gdpr.js',
        [],
        file_exists($js_path) ? (string) filemtime($js_path) : '2.1.0',
        true // In footer
    );

    // Passa la configurazione GA4 al JS
    wp_localize_script('dfn-gdpr-js', 'dfnGdprVars', [
        'ga4Id' => defined('DFN_GA4_MEASUREMENT_ID') ? DFN_GA4_MEASUREMENT_ID : '',
    ]);
}
add_action('wp_enqueue_scripts', 'dfn_gdpr_enqueue_assets', 20);


/**
 * ============================================================
 * 2. HELPER: CHECKBOX INFORMATIVA PRIVACY
 * ============================================================
 */

/**
 * Genera l'HTML della checkbox obbligatoria per l'informativa sulla privacy GDPR.
 *
 * La checkbox è richiesta per il trattamento dei dati personali ai sensi
 * del Reg. UE 2016/679 (GDPR) — art. 6, comma 1, lett. b) e art. 13.
 *
 * @param  string $form_id  Identificativo univoco del form (per l'attributo id).
 * @param  string $context  Contesto del trattamento (es. 'prenotazione', 'tessera').
 * @return string           HTML della checkbox.
 */
function dfn_get_privacy_checkbox_html(string $form_id = 'form', string $context = 'prenotazione'): string
{
    $privacy_url = esc_url(DFN_PRIVACY_POLICY_URL);
    $checkbox_id = 'dfn_privacy_consent_' . esc_attr($form_id);

    $purpose_text = match ($context) {
        'tessera' => 'la gestione e la verifica della tessera associativa',
        'account' => 'la gestione del tuo profilo utente',
        default   => 'la gestione della prenotazione e l\'invio delle comunicazioni correlate',
    };

    $html  = '<div class="dfn-privacy-consent-block" style="margin-top:16px;">';
    $html .= '<div class="dfn-privacy-consent-wrapper">';
    $html .= '<input type="checkbox" ';
    $html .= 'id="' . $checkbox_id . '" ';
    $html .= 'name="dfn_privacy_consent" ';
    $html .= 'class="dfn-privacy-consent-checkbox dfn-privacy-checkbox" ';
    $html .= 'value="1" ';
    $html .= 'required ';
    $html .= 'aria-required="true" ';
    $html .= 'aria-describedby="' . $checkbox_id . '_desc">';
    $html .= '<label for="' . $checkbox_id . '" class="dfn-privacy-consent-label" id="' . $checkbox_id . '_desc">';
    $html .= '<strong>Consenso al trattamento dei dati personali</strong> — ';
    $html .= 'Ho letto e accetto l\'<a href="' . $privacy_url . '" target="_blank" rel="noopener">Informativa sulla Privacy</a> ';
    $html .= 'ai sensi del <strong>Regolamento UE 2016/679 (GDPR)</strong>. ';
    $html .= 'I dati forniti saranno trattati da <em>' . esc_html(DFN_PRIVACY_OWNER_NAME) . '</em> ';
    $html .= 'esclusivamente per ' . $purpose_text . ' e non saranno ceduti a terzi. ';
    $html .= '(<a href="' . $privacy_url . '" target="_blank" rel="noopener">Leggi la Privacy Policy →</a>)';
    $html .= '</label>';
    $html .= '</div>';
    $html .= '<p class="dfn-privacy-error-msg" role="alert">⚠ Devi accettare l\'informativa sulla privacy per continuare.</p>';
    $html .= '</div>';

    return $html;
}


/**
 * ============================================================
 * 3. INTEGRAZIONE WOOCOMMERCE CHECKOUT
 * ============================================================
 */

/**
 * Inietta la checkbox privacy nel checkout WooCommerce prima del riepilogo ordine.
 *
 * @return void
 */
function dfn_wc_checkout_privacy_checkbox(): void
{
    // Non duplicare se WC gestisce già la sua checkbox nativa
    if (wc_get_page_id('privacy_policy') > 0 && 'yes' === get_option('woocommerce_enable_checkout_privacy_policy_text', 'yes')) {
        return;
    }

    echo dfn_get_privacy_checkbox_html('wc_checkout', 'prenotazione');
}
add_action('woocommerce_checkout_before_order_review', 'dfn_wc_checkout_privacy_checkbox', 5);

/**
 * Valida lato server la checkbox privacy nel checkout WooCommerce.
 *
 * @return void
 */
function dfn_wc_validate_privacy_checkbox(): void
{
    if (wc_get_page_id('privacy_policy') > 0 && 'yes' === get_option('woocommerce_enable_checkout_privacy_policy_text', 'yes')) {
        return;
    }

    if (empty($_POST['dfn_privacy_consent'])) {
        wc_add_notice(
            __('È necessario accettare l\'Informativa sulla Privacy per procedere con la prenotazione.', 'dfn-theme'),
            'error'
        );
    }
}
add_action('woocommerce_checkout_process', 'dfn_wc_validate_privacy_checkbox');


/**
 * ============================================================
 * 4. SHORTCODE [dfn_cookie_preferences]
 *
 * Inserisce il pannello interattivo di gestione preferenze cookie.
 * Usare nella pagina Privacy/Cookie Policy per consentire all'utente
 * di modificare le proprie scelte senza riaprire il banner.
 *
 * Utilizzo nel contenuto della pagina WordPress:
 *   [dfn_cookie_preferences]
 * ============================================================
 */

/**
 * Renderizza il pannello inline di gestione preferenze cookie.
 *
 * @return string HTML del pannello.
 */
function dfn_render_cookie_preferences_shortcode(): string
{
    ob_start();
    ?>
    <div id="dfn-inline-cookie-prefs" role="region" aria-label="<?php esc_attr_e('Gestione preferenze cookie', 'dfn-theme'); ?>">

        <div class="dfn-icp-header">
            <h3>⚙️ <?php esc_html_e('Gestisci le tue preferenze sui cookie', 'dfn-theme'); ?></h3>
            <p><?php esc_html_e('Le preferenze attuali sono indicate di seguito. Puoi modificarle in qualsiasi momento — le nuove scelte saranno applicate immediatamente e ricordate per 365 giorni.', 'dfn-theme'); ?></p>
        </div>

        <div class="dfn-icp-rows">

            <!-- Categoria 1: Tecnici (obbligatori) -->
            <div class="dfn-icp-row active" data-category="technical">
                <div class="dfn-icp-row-info">
                    <div class="dfn-icp-row-title">
                        <span class="dfn-icp-row-name">🔒 <?php esc_html_e('Cookie Tecnici e di Funzionamento', 'dfn-theme'); ?></span>
                        <span class="dfn-icp-status dfn-icp-status-on"><?php esc_html_e('✅ Sempre attivi', 'dfn-theme'); ?></span>
                    </div>
                    <div class="dfn-icp-row-desc">
                        <?php esc_html_e('Strettamente necessari al funzionamento del sito. Senza di essi alcune funzioni essenziali non sarebbero disponibili. Non richiedono il tuo consenso.', 'dfn-theme'); ?>
                        <em><?php esc_html_e('Base giuridica: art. 122, c. 1, D.Lgs. 196/2003 — Provv. Garante n. 229/2014', 'dfn-theme'); ?></em>
                    </div>

                    <!-- Tabella cookie tecnici espandibile -->
                    <div class="dfn-icp-cookie-table-section">
                        <button type="button" class="dfn-icp-cookie-table-toggle" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'; this.textContent = this.textContent.includes('▼') ? '▲ Nascondi cookie' : '▼ Mostra cookie';">
                            ▼ <?php esc_html_e('Mostra cookie', 'dfn-theme'); ?>
                        </button>
                        <div class="dfn-icp-table-wrapper" style="display:none;">
                            <table class="dfn-icp-cookie-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Nome', 'dfn-theme'); ?></th>
                                        <th><?php esc_html_e('Fornitore', 'dfn-theme'); ?></th>
                                        <th><?php esc_html_e('Durata', 'dfn-theme'); ?></th>
                                        <th><?php esc_html_e('Finalità', 'dfn-theme'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>wordpress_*</code><br><code>wp-settings-*</code></td>
                                        <td>WordPress</td>
                                        <td><?php esc_html_e('Sessione / 1 anno', 'dfn-theme'); ?></td>
                                        <td><?php esc_html_e('Sessione di autenticazione, preferenze del pannello di amministrazione', 'dfn-theme'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>woocommerce_cart_hash</code><br><code>woocommerce_items_in_cart</code><br><code>wp_woocommerce_session_*</code></td>
                                        <td>WooCommerce</td>
                                        <td><?php esc_html_e('Sessione / 2 giorni', 'dfn-theme'); ?></td>
                                        <td><?php esc_html_e('Gestione del carrello e della sessione di acquisto', 'dfn-theme'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>PHPSESSID</code></td>
                                        <td><?php esc_html_e('Server', 'dfn-theme'); ?></td>
                                        <td><?php esc_html_e('Sessione', 'dfn-theme'); ?></td>
                                        <td><?php esc_html_e('Mantenimento della sessione PHP lato server', 'dfn-theme'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>dfn_consent_*</code></td>
                                        <td><?php echo esc_html(get_bloginfo('name')); ?></td>
                                        <td><?php esc_html_e('365 giorni', 'dfn-theme'); ?></td>
                                        <td><?php esc_html_e('Memorizzazione delle preferenze cookie espresse dall\'utente', 'dfn-theme'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="dfn-icp-row-right">
                    <label class="dfn-cookie-toggle" aria-label="<?php esc_attr_e('Cookie tecnici sempre attivi', 'dfn-theme'); ?>">
                        <input type="checkbox" checked disabled>
                        <span class="dfn-cookie-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <!-- Categoria 2: Analitici -->
            <div class="dfn-icp-row" data-category="analytics">
                <div class="dfn-icp-row-info">
                    <div class="dfn-icp-row-title">
                        <span class="dfn-icp-row-name">📊 <?php esc_html_e('Cookie Analitici', 'dfn-theme'); ?></span>
                        <span class="dfn-icp-status dfn-icp-status-off"><?php esc_html_e('⭕ Non attivo', 'dfn-theme'); ?></span>
                    </div>
                    <div class="dfn-icp-row-desc">
                        <?php esc_html_e('Raccolgono dati anonimi su come i visitatori utilizzano il sito (pagine visitate, durata sessione, sorgente del traffico). L\'indirizzo IP è anonimizzato automaticamente. I dati sono aggregati e non consentono l\'identificazione personale.', 'dfn-theme'); ?>
                        <em><?php esc_html_e('Base giuridica: consenso dell\'interessato (art. 6.1.a GDPR)', 'dfn-theme'); ?></em>
                    </div>

                    <!-- Tabella cookie analitici espandibile -->
                    <div class="dfn-icp-cookie-table-section">
                        <button type="button" class="dfn-icp-cookie-table-toggle" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'; this.textContent = this.textContent.includes('▼') ? '▲ Nascondi cookie' : '▼ Mostra cookie';">
                            ▼ <?php esc_html_e('Mostra cookie', 'dfn-theme'); ?>
                        </button>
                        <div class="dfn-icp-table-wrapper" style="display:none;">
                            <table class="dfn-icp-cookie-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Nome', 'dfn-theme'); ?></th>
                                        <th><?php esc_html_e('Fornitore', 'dfn-theme'); ?></th>
                                        <th><?php esc_html_e('Durata', 'dfn-theme'); ?></th>
                                        <th><?php esc_html_e('Finalità', 'dfn-theme'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>_ga</code><br><code>_ga_*</code></td>
                                        <td>Google Analytics 4 (Google LLC)</td>
                                        <td><?php esc_html_e('13 mesi', 'dfn-theme'); ?></td>
                                        <td><?php esc_html_e('Distingue utenti unici e sessioni; tiene traccia del numero di sessioni e dei dati relativi ai visitatori.', 'dfn-theme'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>_gid</code></td>
                                        <td>Google Analytics (Google LLC)</td>
                                        <td><?php esc_html_e('24 ore', 'dfn-theme'); ?></td>
                                        <td><?php esc_html_e('Distingue gli utenti nel corso della stessa giornata.', 'dfn-theme'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            <p style="font-size:11px; color:#94a3b8; margin-top:6px; line-height:1.4;">
                                <?php esc_html_e('Google LLC è certificata EU-US Data Privacy Framework (decisione di adeguatezza CE del 10/07/2023). ', 'dfn-theme'); ?>
                                <a href="https://policies.google.com/privacy?hl=it" target="_blank" rel="noopener"><?php esc_html_e('Privacy Policy di Google →', 'dfn-theme'); ?></a>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="dfn-icp-row-right">
                    <label class="dfn-cookie-toggle" aria-label="<?php esc_attr_e('Abilita cookie analitici', 'dfn-theme'); ?>">
                        <input type="checkbox" id="dfn-icp-toggle-analytics">
                        <span class="dfn-cookie-toggle-slider"></span>
                    </label>
                </div>
            </div>

        </div><!-- .dfn-icp-rows -->

        <!-- Pulsanti azione -->
        <div class="dfn-icp-actions">
            <button type="button" class="dfn-icp-btn dfn-icp-btn-save" id="dfn-icp-btn-save">
                💾 <?php esc_html_e('Salva preferenze', 'dfn-theme'); ?>
            </button>
            <button type="button" class="dfn-icp-btn dfn-icp-btn-accept-all" id="dfn-icp-btn-accept-all">
                ✓ <?php esc_html_e('Accetta tutti', 'dfn-theme'); ?>
            </button>
            <button type="button" class="dfn-icp-btn dfn-icp-btn-reject-all" id="dfn-icp-btn-reject-all">
                <?php esc_html_e('Solo tecnici', 'dfn-theme'); ?>
            </button>
        </div>

        <!-- Feedback salvataggio -->
        <div id="dfn-icp-feedback" role="status" aria-live="polite">
            ✅ <?php esc_html_e('Preferenze aggiornate e salvate correttamente.', 'dfn-theme'); ?>
        </div>

    </div><!-- #dfn-inline-cookie-prefs -->
    <?php
    return ob_get_clean();
}
add_shortcode('dfn_cookie_preferences', 'dfn_render_cookie_preferences_shortcode');


/**
 * ============================================================
 * 5. COOKIE BANNER — OUTPUT HTML NEL FOOTER
 * ============================================================
 */

/**
 * Inserisce l'HTML del cookie banner nella pagina.
 *
 * @return void
 */
function dfn_render_cookie_banner(): void
{
    if (is_admin()) {
        return;
    }

    $privacy_url = esc_url(DFN_PRIVACY_POLICY_URL);
    $site_name   = esc_html(get_bloginfo('name'));
    ?>
    <!-- DFN Cookie Consent Banner — GDPR/ePrivacy Compliant -->
    <div id="dfn-cookie-banner-overlay" role="dialog" aria-modal="true" aria-labelledby="dfn-cookie-banner-title">
        <div id="dfn-cookie-banner">

            <div class="dfn-cookie-banner-header">
                <div class="dfn-cookie-banner-icon">🍪</div>
                <div>
                    <h2 class="dfn-cookie-banner-title" id="dfn-cookie-banner-title">
                        <?php esc_html_e('Utilizziamo i cookie', 'dfn-theme'); ?>
                    </h2>
                    <p class="dfn-cookie-banner-desc">
                        <?php echo sprintf(
                            /* translators: %s = nome del sito */
                            esc_html__('%s utilizza cookie tecnici necessari al funzionamento del sito e, previo consenso, cookie analitici per migliorare l\'esperienza di navigazione. Puoi scegliere quali accettare.', 'dfn-theme'),
                            '<strong>' . $site_name . '</strong>'
                        ); ?>
                        <?php if ($privacy_url) : ?>
                            <a href="<?php echo $privacy_url; ?>" target="_blank" rel="noopener">
                                <?php esc_html_e('Cookie Policy →', 'dfn-theme'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="dfn-cookie-categories">

                <!-- Categoria 1: Cookie Tecnici (obbligatori) -->
                <div class="dfn-cookie-category active" data-category="technical">
                    <div class="dfn-cookie-category-info">
                        <div class="dfn-cookie-category-name">
                            🔒 <?php esc_html_e('Cookie Tecnici e di Funzionamento', 'dfn-theme'); ?>
                            <span class="dfn-cookie-category-badge dfn-badge-required"><?php esc_html_e('Sempre attivi', 'dfn-theme'); ?></span>
                        </div>
                        <div class="dfn-cookie-category-desc">
                            <?php esc_html_e('Necessari per il funzionamento del sito. Gestiscono la sessione utente, il carrello acquisti, la sicurezza (nonce WordPress), le preferenze di accesso e la navigazione base. Non richiedono consenso ai sensi dell\'art. 122, c. 1, D.Lgs. 196/2003 e del Provvedimento del Garante n. 229/2014.', 'dfn-theme'); ?>
                            <br><em style="font-size:10.5px; color:#94a3b8; margin-top:3px; display:block;"><?php esc_html_e('Cookie: wordpress_*, woocommerce_*, wp-settings-*, PHPSESSID, dfn_consent_*', 'dfn-theme'); ?></em>
                        </div>
                    </div>
                    <label class="dfn-cookie-toggle" aria-label="<?php esc_attr_e('Cookie tecnici sempre attivi', 'dfn-theme'); ?>">
                        <input type="checkbox" id="dfn-toggle-technical" checked disabled>
                        <span class="dfn-cookie-toggle-slider"></span>
                    </label>
                </div>

                <!-- Categoria 2: Cookie Analitici -->
                <div class="dfn-cookie-category" data-category="analytics">
                    <div class="dfn-cookie-category-info">
                        <div class="dfn-cookie-category-name">
                            📊 <?php esc_html_e('Cookie Analitici', 'dfn-theme'); ?>
                            <span class="dfn-cookie-category-badge dfn-badge-optional"><?php esc_html_e('Opzionali', 'dfn-theme'); ?></span>
                        </div>
                        <div class="dfn-cookie-category-desc">
                            <?php esc_html_e('Ci aiutano a capire come i visitatori interagiscono con il sito raccogliendo informazioni anonime (pagine visitate, durata sessione, sorgente del traffico). I dati sono aggregati con IP anonimizzato e non consentono l\'identificazione personale.', 'dfn-theme'); ?>
                            <br><em style="font-size:10.5px; color:#94a3b8; margin-top:3px; display:block;"><?php esc_html_e('Servizi: Google Analytics 4 (con IP anonimizzato). Base giuridica: consenso (art. 6.1.a GDPR).', 'dfn-theme'); ?></em>
                        </div>
                    </div>
                    <label class="dfn-cookie-toggle" aria-label="<?php esc_attr_e('Abilita cookie analitici', 'dfn-theme'); ?>">
                        <input type="checkbox" id="dfn-toggle-analytics">
                        <span class="dfn-cookie-toggle-slider"></span>
                    </label>
                </div>

            </div><!-- .dfn-cookie-categories -->

            <div class="dfn-cookie-banner-actions">
                <button type="button" class="dfn-cookie-btn dfn-cookie-btn-accept-all" id="dfn-cookie-accept-all">
                    ✓ <?php esc_html_e('Accetta tutti', 'dfn-theme'); ?>
                </button>
                <button type="button" class="dfn-cookie-btn dfn-cookie-btn-save" id="dfn-cookie-save">
                    <?php esc_html_e('Salva preferenze', 'dfn-theme'); ?>
                </button>
                <button type="button" class="dfn-cookie-btn dfn-cookie-btn-reject" id="dfn-cookie-reject-all">
                    <?php esc_html_e('Solo tecnici', 'dfn-theme'); ?>
                </button>
            </div>

        </div><!-- #dfn-cookie-banner -->
    </div><!-- #dfn-cookie-banner-overlay -->

    <!-- Link persistente "Gestisci preferenze cookie" -->
    <a href="#" id="dfn-cookie-manage-link" role="button" aria-label="<?php esc_attr_e('Gestisci preferenze cookie', 'dfn-theme'); ?>">
        🍪 <?php esc_html_e('Cookie', 'dfn-theme'); ?>
    </a>
    <!-- / DFN Cookie Consent Banner -->
    <?php
}
add_action('wp_footer', 'dfn_render_cookie_banner', 100);


/**
 * ============================================================
 * 5. CREAZIONE AUTOMATICA PAGINA PRIVACY POLICY
 * ============================================================
 */

/**
 * Crea la pagina Privacy Policy in WordPress se non esiste ancora,
 * la popola con un template GDPR-compliant e la imposta come pagina
 * privacy ufficiale del sito (usata da WP e WooCommerce).
 *
 * @return void
 */
function dfn_create_privacy_policy_page(): void
{
    // Controlla se esiste già una pagina privacy impostata
    $existing_pp_id = (int) get_option('wp_page_for_privacy_policy');
    if ($existing_pp_id > 0 && get_post_status($existing_pp_id) === 'publish') {
        return; // Già esiste e pubblicata
    }

    // Controlla se esiste già una pagina con slug /privacy-policy/
    $existing = get_page_by_path('privacy-policy');
    if ($existing) {
        // Imposta come pagina privacy di WP se non ancora impostata
        if (!$existing_pp_id) {
            update_option('wp_page_for_privacy_policy', $existing->ID);
        }
        return;
    }

    $site_name    = get_bloginfo('name');
    $site_url     = home_url();
    $admin_email  = get_bloginfo('admin_email');
    $current_date = date_i18n('d/m/Y');

    $content = dfn_get_privacy_policy_template($site_name, $site_url, $admin_email);

    $page_id = wp_insert_post([
        'post_title'   => 'Informativa sulla Privacy',
        'post_name'    => 'privacy-policy',
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_author'  => 1,
        'meta_input'   => [
            '_dfn_gdpr_created' => $current_date,
        ],
    ]);

    if ($page_id && ! is_wp_error($page_id)) {
        // Imposta come pagina privacy ufficiale di WordPress
        update_option('wp_page_for_privacy_policy', $page_id);
    }
}
add_action('after_switch_theme', 'dfn_create_privacy_policy_page');
add_action('admin_init', 'dfn_create_privacy_policy_page');


/**
 * Genera il contenuto HTML della pagina Privacy Policy (template GDPR-compliant).
 *
 * I segnaposto in MAIUSCOLO devono essere sostituiti con i dati reali del titolare.
 *
 * @param  string $site_name   Nome del sito.
 * @param  string $site_url    URL del sito.
 * @param  string $admin_email Email dell'amministratore.
 * @return string              Contenuto HTML della pagina.
 */
function dfn_get_privacy_policy_template(string $site_name, string $site_url, string $admin_email): string
{
    $current_date = date_i18n('d/m/Y');

    return <<<HTML
<!-- wp:paragraph {"className":"dfn-privacy-update-note"} -->
<p class="dfn-privacy-update-note"><em>Ultimo aggiornamento: {$current_date}</em></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>1. Titolare del Trattamento</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Il Titolare del trattamento dei dati personali raccolti tramite il sito <strong>{$site_url}</strong> è:</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>
<strong>Ragione sociale:</strong> [INSERIRE RAGIONE SOCIALE]<br>
<strong>Sede legale:</strong> [INSERIRE INDIRIZZO SEDE LEGALE]<br>
<strong>Codice Fiscale / P.IVA:</strong> [INSERIRE CF/P.IVA]<br>
<strong>Email:</strong> {$admin_email}<br>
<strong>Telefono:</strong> [INSERIRE NUMERO DI TELEFONO]<br>
<strong>Responsabile della Protezione dei Dati (DPO):</strong> [INSERIRE SE PRESENTE, ALTRIMENTI RIMUOVERE]
</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>2. Tipologie di Dati Raccolti</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Il sito raccoglie le seguenti categorie di dati personali:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>
<li><strong>Dati anagrafici:</strong> nome, cognome</li>
<li><strong>Dati di contatto:</strong> indirizzo email, numero di telefono</li>
<li><strong>Dati di navigazione:</strong> indirizzo IP (anonimizzato), browser utilizzato, pagine visitate, durata della sessione (raccolti tramite cookie analitici, solo previo consenso)</li>
<li><strong>Dati della prenotazione:</strong> evento prenotato, numero di partecipanti, eventuali note</li>
<li><strong>Dati della tessera associativa FAI:</strong> nome, cognome e numero tessera (per i soci FAI che richiedono la tariffa agevolata)</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Non raccogliamo categorie particolari di dati (dati sensibili) ai sensi dell'art. 9 del GDPR, né dati giudiziari ai sensi dell'art. 10.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>3. Finalità e Basi Giuridiche del Trattamento</h2>
<!-- /wp:heading -->

<!-- wp:table -->
<figure class="wp-block-table"><table>
<thead><tr><th>Finalità</th><th>Base giuridica (art. 6 GDPR)</th><th>Conservazione</th></tr></thead>
<tbody>
<tr><td>Gestione della prenotazione all'evento</td><td>Esecuzione di un contratto (lett. b)</td><td>5 anni dall'evento</td></tr>
<tr><td>Invio email di conferma e promemoria</td><td>Esecuzione di un contratto (lett. b)</td><td>Fino all'evasione della comunicazione</td></tr>
<tr><td>Gestione della tessera FAI e dello sconto associativo</td><td>Esecuzione di un contratto (lett. b)</td><td>1 anno dalla verifica</td></tr>
<tr><td>Adempimenti fiscali e contabili</td><td>Obbligo legale (lett. c)</td><td>10 anni (normativa fiscale)</td></tr>
<tr><td>Analisi statistica del sito (Google Analytics 4)</td><td>Consenso (lett. a)</td><td>14 mesi (impostazione GA4)</td></tr>
<tr><td>Sicurezza del sito e prevenzione delle frodi</td><td>Legittimo interesse (lett. f)</td><td>30 giorni nei log di sistema</td></tr>
</tbody>
</table></figure>
<!-- /wp:table -->

<!-- wp:heading -->
<h2>4. Modalità del Trattamento</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>I dati personali sono trattati con strumenti informatici e/o telematici, con modalità organizzative e logiche strettamente correlate alle finalità indicate. I dati sono protetti mediante misure di sicurezza adeguate, tra cui:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>
<li>Connessione cifrata HTTPS (certificato SSL/TLS)</li>
<li>Accesso ai dati riservato al personale autorizzato mediante credenziali individuali</li>
<li>Hosting su server con sede nell'Unione Europea</li>
<li>Backup periodici con conservazione sicura</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>5. Comunicazione e Diffusione dei Dati</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>I dati personali non vengono venduti né ceduti a terzi per finalità commerciali. Possono essere comunicati a:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>
<li><strong>Fornitori di servizi tecnici</strong> (hosting, piattaforma email) che agiscono come Responsabili del Trattamento ai sensi dell'art. 28 GDPR e garantiscono adeguate misure di sicurezza</li>
<li><strong>Google LLC</strong> (per Google Analytics 4, solo previo consenso) — i dati di navigazione sono trasferiti negli USA ai sensi dell'EU-US Data Privacy Framework (decisione di adeguatezza della Commissione UE del 10/07/2023)</li>
<li><strong>Autorità pubbliche</strong> nei casi previsti dalla legge</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>6. Conservazione dei Dati</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>I dati sono conservati per il tempo strettamente necessario alle finalità per cui sono stati raccolti, come indicato nella tabella al punto 3. Al termine del periodo di conservazione i dati vengono cancellati o resi anonimi in modo irreversibile.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>7. Diritti degli Interessati</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Ai sensi degli artt. 15–22 del GDPR, hai il diritto di:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>
<li><strong>Accesso (art. 15):</strong> ottenere conferma che siano in corso trattamenti di dati che ti riguardano e riceverne copia</li>
<li><strong>Rettifica (art. 16):</strong> richiedere la correzione di dati inesatti o incompleti</li>
<li><strong>Cancellazione (art. 17):</strong> ottenere la cancellazione dei tuoi dati ("diritto all'oblio"), nei casi previsti dalla norma</li>
<li><strong>Limitazione (art. 18):</strong> richiedere la limitazione del trattamento</li>
<li><strong>Portabilità (art. 20):</strong> ricevere i dati in un formato strutturato e leggibile da dispositivo automatico</li>
<li><strong>Opposizione (art. 21):</strong> opporti al trattamento basato su legittimo interesse</li>
<li><strong>Revoca del consenso:</strong> revocare in qualsiasi momento il consenso al trattamento dei dati analitici, senza pregiudizio per la liceità del trattamento svolto prima della revoca</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Per esercitare i tuoi diritti, scrivi a: <strong>{$admin_email}</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Hai inoltre il diritto di proporre reclamo al Garante per la Protezione dei Dati Personali (<a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">www.garanteprivacy.it</a>).</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>8. Cookie Policy</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Questo sito utilizza cookie e tecnologie simili. I cookie sono piccoli file di testo salvati nel tuo browser che permettono il funzionamento del sito e la raccolta di informazioni statistiche. Puoi gestire le tue preferenze in qualsiasi momento tramite il banner cookie presente in fondo alla pagina.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>8.1 Cookie Tecnici (sempre attivi)</h3>
<!-- /wp:heading -->

<!-- wp:table -->
<figure class="wp-block-table"><table>
<thead><tr><th>Nome cookie</th><th>Fornitore</th><th>Durata</th><th>Finalità</th></tr></thead>
<tbody>
<tr><td>wordpress_*, wp-settings-*</td><td>WordPress</td><td>Sessione / 1 anno</td><td>Sessione autenticazione, preferenze admin</td></tr>
<tr><td>woocommerce_*</td><td>WooCommerce</td><td>Sessione / 2 giorni</td><td>Gestione carrello e sessione acquisto</td></tr>
<tr><td>PHPSESSID</td><td>Server</td><td>Sessione</td><td>Mantenimento sessione PHP</td></tr>
<tr><td>dfn_consent_*</td><td>{$site_name}</td><td>365 giorni</td><td>Memorizzazione preferenze cookie</td></tr>
</tbody>
</table></figure>
<!-- /wp:table -->

<!-- wp:heading {"level":3} -->
<h3>8.2 Cookie Analitici (su consenso)</h3>
<!-- /wp:heading -->

<!-- wp:table -->
<figure class="wp-block-table"><table>
<thead><tr><th>Nome cookie</th><th>Fornitore</th><th>Durata</th><th>Finalità</th></tr></thead>
<tbody>
<tr><td>_ga, _ga_*</td><td>Google Analytics 4</td><td>13 mesi</td><td>Analisi statistica del traffico (IP anonimizzato)</td></tr>
<tr><td>_gid</td><td>Google Analytics</td><td>24 ore</td><td>Distinzione utenti unici</td></tr>
</tbody>
</table></figure>
<!-- /wp:table -->

<!-- wp:paragraph -->
<p>Per maggiori informazioni sui cookie di Google: <a href="https://policies.google.com/technologies/cookies?hl=it" target="_blank" rel="noopener">policies.google.com</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Per disabilitare Google Analytics in tutti i siti: <a href="https://tools.google.com/dlpage/gaoptout" target="_blank" rel="noopener">Google Analytics Opt-out</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>8.3 Gestisci le tue preferenze sui cookie</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Puoi modificare le tue preferenze sui cookie in qualsiasi momento utilizzando il pannello qui di seguito. Le scelte saranno salvate nel tuo browser per 365 giorni.</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[dfn_cookie_preferences]
<!-- /wp:shortcode -->

<!-- wp:heading -->
<h2>9. Modifiche alla Presente Informativa</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Il Titolare si riserva il diritto di modificare la presente informativa in qualsiasi momento per adeguarla a variazioni normative o tecniche. Ogni modifica sarà indicata con aggiornamento della data in cima alla pagina. Ti invitiamo a consultare regolarmente questa pagina.</p>
<!-- /wp:paragraph -->

<!-- wp:separator -->
<hr class="wp-block-separator"/>
<!-- /wp:separator -->

<!-- wp:paragraph -->
<p><em>Informativa redatta ai sensi del Regolamento UE 2016/679 (GDPR), del D.Lgs. 196/2003 (Codice Privacy) come modificato dal D.Lgs. 101/2018, e delle Linee Guida sui cookie del Garante Privacy (Provvedimento n. 231 del 10 giugno 2021).</em></p>
<!-- /wp:paragraph -->
HTML;
}
