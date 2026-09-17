<?php
/**
 * DFN Roles & Permissions Manager — Core Engine
 *
 * Gestione centralizzata e modulare dei Ruoli e delle Capabilities FAI.
 * Consente la definizione dinamica dei ruoli, l'associazione delle attività
 * per modulo (Prenotazioni, Convenzioni, ecc.) e la sincronizzazione con WP_Roles.
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Restituisce l'elenco dei moduli di sistema supportati.
 *
 * @return array<string, array{label: string, icon: string, description: string}>
 */
function dfn_get_modules_registry(): array
{
    return [
        'prenotazioni' => [
            'label'       => __('FAI Prenotazioni', 'dfn-theme'),
            'icon'        => '🎟️',
            'dashicon'    => 'dashicons-tickets-alt',
            'description' => __('Gestione eventi, turni, prenotazioni, check-in, botteghino e scanner live.', 'dfn-theme'),
        ],
        'volontari'    => [
            'label'       => __('Volontari FAI', 'dfn-theme'),
            'icon'        => '👥',
            'dashicon'    => 'dashicons-groups',
            'description' => __('Anagrafica volontari, tessere FAI, logistica eventi, matrice turni, sondaggi e riunioni di delegazione.', 'dfn-theme'),
        ],
    ];
}

/**
 * Restituisce il catalogo completo di tutte le attività (capabilities) registrate per ciascun modulo.
 *
 * @return array<string, array<string, array{label: string, icon: string, description: string}>>
 */
function dfn_get_activities_catalog(): array
{
    return [
        'prenotazioni' => [
            'dfn_act_events_manage'   => [
                'label'       => __('Eventi & Aggiungi evento', 'dfn-theme'),
                'icon'        => '📅',
                'description' => __('Creazione, modifica, eliminazione e configurazione turni degli eventi.', 'dfn-theme'),
            ],
            'dfn_act_scanner'         => [
                'label'       => __('Scanner Live QR', 'dfn-theme'),
                'icon'        => '🔍',
                'description' => __('Utilizzo dello scanner videocamera per la validazione istantanea dei biglietti.', 'dfn-theme'),
            ],
            'dfn_act_checkin'         => [
                'label'       => __('Check-in Banchetto', 'dfn-theme'),
                'icon'        => '🎟️',
                'description' => __('Gestione ingressi manuali, validazione soci e firma presenze al banchetto.', 'dfn-theme'),
            ],
            'dfn_act_quick_booking'   => [
                'label'       => __('Inserimento Rapido', 'dfn-theme'),
                'icon'        => '⚡',
                'description' => __('Registrazione diretta di nuove prenotazioni da segreteria/operatore.', 'dfn-theme'),
            ],
            'dfn_act_verify_bookings' => [
                'label'       => __('Verifica Prenotazioni', 'dfn-theme'),
                'icon'        => '📋',
                'description' => __('Consultazione elenco prenotati, filtri per turno, spostamento turni e annullamento.', 'dfn-theme'),
            ],
            'dfn_act_boxoffice'       => [
                'label'       => __('Botteghino Live', 'dfn-theme'),
                'icon'        => '🎫',
                'description' => __('Emissione immediata biglietti sul posto e incasso contanti/POS.', 'dfn-theme'),
            ],
            'dfn_act_waitlist'        => [
                'label'       => __('Lista d\'Attesa', 'dfn-theme'),
                'icon'        => '⏳',
                'description' => __('Gestione richieste in overbooking e invio inviti a posti liberati.', 'dfn-theme'),
            ],
            'dfn_act_fai_members'     => [
                'label'       => __('Soci FAI', 'dfn-theme'),
                'icon'        => '🪪',
                'description' => __('Anagrafica soci, convalida tessere e caricamento massivo elenchi soci.', 'dfn-theme'),
            ],
            'dfn_act_reports'         => [
                'label'       => __('Report Ingressi', 'dfn-theme'),
                'icon'        => '📊',
                'description' => __('Statistiche di affluenza, grafici orari e log delle presenze per turno.', 'dfn-theme'),
            ],
            'dfn_act_financials'      => [
                'label'       => __('Bilancio Eventi', 'dfn-theme'),
                'icon'        => '💰',
                'description' => __('Prospetto economico, riepilogo incassi (online, contanti, POS) e donazioni.', 'dfn-theme'),
            ],
            'dfn_act_reviews'         => [
                'label'       => __('Recensioni Eventi', 'dfn-theme'),
                'icon'        => '⭐',
                'description' => __('Consultazione e moderazione dei feedback lasciati dai partecipanti.', 'dfn-theme'),
            ],
            'dfn_act_system_logs'     => [
                'label'       => __('Log di Sistema', 'dfn-theme'),
                'icon'        => '📜',
                'description' => __('Visualizzazione storico operazioni, audit log ed errori di sistema.', 'dfn-theme'),
            ],
            'dfn_act_settings'        => [
                'label'       => __('Impostazioni Prenotazioni', 'dfn-theme'),
                'icon'        => '⚙️',
                'description' => __('Configurazione notifiche email, testi, colori, cron e parametri generali.', 'dfn-theme'),
            ],
        ],
        'volontari'    => [
            'dfn_act_vol_roster'      => [
                'label'       => __('Anagrafica & Tessere Volontari', 'dfn-theme'),
                'icon'        => '👥',
                'description' => __('Consultazione elenco volontari, aggiunta, modifica anagrafica, tessere e competenze.', 'dfn-theme'),
            ],
            'dfn_act_vol_logistics'   => [
                'label'       => __('Pianificazione & Matrice Turni', 'dfn-theme'),
                'icon'        => '🧩',
                'description' => __('Creazione eventi logistica, configurazione luoghi/slot, assegnazione manuale e pubblicazione turni.', 'dfn-theme'),
            ],
            'dfn_act_vol_auto_assign' => [
                'label'       => __('Assegnazione Automatica & Reset', 'dfn-theme'),
                'icon'        => '🤖',
                'description' => __('Esecuzione dell\'algoritmo intelligente di assegnazione turni e azzeramento board.', 'dfn-theme'),
            ],
            'dfn_act_vol_surveys'     => [
                'label'       => __('Gestione Sondaggi Disponibilità', 'dfn-theme'),
                'icon'        => '📊',
                'description' => __('Apertura, configurazione scadenze, consultazione preferenze e chiusura sondaggi.', 'dfn-theme'),
            ],
            'dfn_act_vol_meetings'    => [
                'label'       => __('Riunioni di Delegazione', 'dfn-theme'),
                'icon'        => '📅',
                'description' => __('Pianificazione calendario riunioni, ordini del giorno e link di partecipazione.', 'dfn-theme'),
            ],
            'dfn_act_vol_roles'       => [
                'label'       => __('Mansioni & Competenze Operative', 'dfn-theme'),
                'icon'        => '🏷️',
                'description' => __('Configurazione catalogo mansioni (Guida, Banchetto, Scuola, ecc.) e requisiti di sicurezza.', 'dfn-theme'),
            ],
        ],
    ];
}

/**
 * Restituisce la definizione predefinita dei ruoli FAI del sistema.
 *
 * @return array<string, array{label: string, is_system: bool, module: string, description: string}>
 */
function dfn_get_default_roles(): array
{
    return [
        'administrator'       => [
            'label'       => __('Amministratore', 'dfn-theme'),
            'is_system'   => true,
            'module'      => 'all',
            'description' => __('Accesso completo a tutte le funzioni e impostazioni di tutti i moduli.', 'dfn-theme'),
        ],
        'dfn_segreteria'      => [
            'label'       => __('Segreteria FAI', 'dfn-theme'),
            'is_system'   => false,
            'module'      => 'prenotazioni',
            'description' => __('Gestione ordinaria prenotazioni, inserimento rapido, soci e liste d\'attesa.', 'dfn-theme'),
        ],
        'dfn_banchetto'       => [
            'label'       => __('Banchetto & Accoglienza', 'dfn-theme'),
            'is_system'   => false,
            'module'      => 'prenotazioni',
            'description' => __('Operatività all\'evento: check-in, botteghino live e validazione QR.', 'dfn-theme'),
        ],
        'dfn_validatore'      => [
            'label'       => __('Validatore QR', 'dfn-theme'),
            'is_system'   => false,
            'module'      => 'prenotazioni',
            'description' => __('Esclusivamente scansione e convalida biglietti tramite fotocamera.', 'dfn-theme'),
        ],
        'dfn_coord_volontari' => [
            'label'       => __('Coordinatore Volontari FAI', 'dfn-theme'),
            'is_system'   => false,
            'module'      => 'volontari',
            'description' => __('Gestione completa anagrafica volontari, logistica turni, sondaggi e riunioni di delegazione.', 'dfn-theme'),
        ],
        'dfn_volunteer'       => [
            'label'       => __('Volontario FAI', 'dfn-theme'),
            'is_system'   => false,
            'module'      => 'volontari',
            'description' => __('Volontario operativo di delegazione.', 'dfn-theme'),
        ],
    ];
}

/**
 * Restituisce la matrice predefinita dei permessi per i ruoli di base.
 *
 * @return array<string, array<string, bool>>
 */
function dfn_get_default_roles_matrix(): array
{
    return [
        'administrator'       => [
            // Administrator ha tutto abilitato
            'dfn_act_events_manage'   => true,
            'dfn_act_scanner'         => true,
            'dfn_act_checkin'         => true,
            'dfn_act_quick_booking'   => true,
            'dfn_act_verify_bookings' => true,
            'dfn_act_boxoffice'       => true,
            'dfn_act_waitlist'        => true,
            'dfn_act_fai_members'     => true,
            'dfn_act_reports'         => true,
            'dfn_act_financials'      => true,
            'dfn_act_reviews'         => true,
            'dfn_act_system_logs'     => true,
            'dfn_act_settings'        => true,
            'dfn_act_vol_roster'      => true,
            'dfn_act_vol_logistics'   => true,
            'dfn_act_vol_auto_assign' => true,
            'dfn_act_vol_surveys'     => true,
            'dfn_act_vol_meetings'    => true,
            'dfn_act_vol_roles'       => true,
        ],
        'dfn_segreteria'      => [
            'dfn_act_events_manage'   => false,
            'dfn_act_scanner'         => false,
            'dfn_act_checkin'         => true,
            'dfn_act_quick_booking'   => true,
            'dfn_act_verify_bookings' => true,
            'dfn_act_boxoffice'       => false,
            'dfn_act_waitlist'        => true,
            'dfn_act_fai_members'     => true,
            'dfn_act_reports'         => true,
            'dfn_act_financials'      => false,
            'dfn_act_reviews'         => false,
            'dfn_act_system_logs'     => false,
            'dfn_act_settings'        => false,
            'dfn_act_vol_roster'      => false,
            'dfn_act_vol_logistics'   => false,
            'dfn_act_vol_auto_assign' => false,
            'dfn_act_vol_surveys'     => false,
            'dfn_act_vol_meetings'    => false,
            'dfn_act_vol_roles'       => false,
        ],
        'dfn_banchetto'       => [
            'dfn_act_events_manage'   => false,
            'dfn_act_scanner'         => true,
            'dfn_act_checkin'         => true,
            'dfn_act_quick_booking'   => false,
            'dfn_act_verify_bookings' => false,
            'dfn_act_boxoffice'       => true,
            'dfn_act_waitlist'        => false,
            'dfn_act_fai_members'     => true,
            'dfn_act_reports'         => false,
            'dfn_act_financials'      => false,
            'dfn_act_reviews'         => false,
            'dfn_act_system_logs'     => false,
            'dfn_act_settings'        => false,
            'dfn_act_vol_roster'      => false,
            'dfn_act_vol_logistics'   => false,
            'dfn_act_vol_auto_assign' => false,
            'dfn_act_vol_surveys'     => false,
            'dfn_act_vol_meetings'    => false,
            'dfn_act_vol_roles'       => false,
        ],
        'dfn_validatore'      => [
            'dfn_act_events_manage'   => false,
            'dfn_act_scanner'         => true,
            'dfn_act_checkin'         => false,
            'dfn_act_quick_booking'   => false,
            'dfn_act_verify_bookings' => false,
            'dfn_act_boxoffice'       => false,
            'dfn_act_waitlist'        => false,
            'dfn_act_fai_members'     => false,
            'dfn_act_reports'         => false,
            'dfn_act_financials'      => false,
            'dfn_act_reviews'         => false,
            'dfn_act_system_logs'     => false,
            'dfn_act_settings'        => false,
            'dfn_act_vol_roster'      => false,
            'dfn_act_vol_logistics'   => false,
            'dfn_act_vol_auto_assign' => false,
            'dfn_act_vol_surveys'     => false,
            'dfn_act_vol_meetings'    => false,
            'dfn_act_vol_roles'       => false,
        ],
        'dfn_coord_volontari' => [
            'dfn_act_events_manage'   => false,
            'dfn_act_scanner'         => false,
            'dfn_act_checkin'         => false,
            'dfn_act_quick_booking'   => false,
            'dfn_act_verify_bookings' => false,
            'dfn_act_boxoffice'       => false,
            'dfn_act_waitlist'        => false,
            'dfn_act_fai_members'     => false,
            'dfn_act_reports'         => false,
            'dfn_act_financials'      => false,
            'dfn_act_reviews'         => false,
            'dfn_act_system_logs'     => false,
            'dfn_act_settings'        => false,
            'dfn_act_vol_roster'      => true,
            'dfn_act_vol_logistics'   => true,
            'dfn_act_vol_auto_assign' => true,
            'dfn_act_vol_surveys'     => true,
            'dfn_act_vol_meetings'    => true,
            'dfn_act_vol_roles'       => true,
        ],
    ];
}

/**
 * Restituisce i ruoli correnti memorizzati (o i default se non presenti).
 *
 * @return array<string, array{label: string, is_system: bool, modules: array<string>, description: string}>
 */
function dfn_get_stored_roles(): array
{
    $stored = get_option('dfn_custom_roles', null);
    if (! is_array($stored) || empty($stored)) {
        $stored = dfn_get_default_roles();
        update_option('dfn_custom_roles', $stored);
    } else {
        $defaults = dfn_get_default_roles();
        $changed = false;
        foreach ($stored as $slug => &$r_data) {
            // Normalizzazione in array 'modules'
            if (! isset($r_data['modules'])) {
                if (isset($r_data['module'])) {
                    if ($r_data['module'] === 'all') {
                        $r_data['modules'] = ['prenotazioni', 'volontari'];
                    } elseif (! empty($r_data['module'])) {
                        $r_data['modules'] = [(string) $r_data['module']];
                    } else {
                        $r_data['modules'] = [];
                    }
                } else {
                    $r_data['modules'] = isset($defaults[$slug]['module']) && $defaults[$slug]['module'] === 'all' 
                        ? ['prenotazioni', 'volontari'] 
                        : (isset($defaults[$slug]['module']) ? [(string) $defaults[$slug]['module']] : []);
                }
                $changed = true;
            }
        }
        if (! isset($stored['dfn_coord_volontari'])) {
            $stored['dfn_coord_volontari'] = $defaults['dfn_coord_volontari'];
            $stored['dfn_coord_volontari']['modules'] = ['volontari'];
            $changed = true;
        }
        if (! isset($stored['dfn_volunteer'])) {
            $stored['dfn_volunteer'] = $defaults['dfn_volunteer'];
            $stored['dfn_volunteer']['modules'] = ['volontari'];
            $changed = true;
        }
        if ($changed) {
            update_option('dfn_custom_roles', $stored);
        }
    }
    return $stored;
}

/**
 * Restituisce la matrice dei permessi memorizzata (o i default se non presente).
 *
 * @return array<string, array<string, bool>>
 */
function dfn_get_stored_roles_matrix(): array
{
    $matrix = get_option('dfn_roles_matrix', null);
    if (! is_array($matrix) || empty($matrix)) {
        $matrix = dfn_get_default_roles_matrix();
        update_option('dfn_roles_matrix', $matrix);
    }
    return $matrix;
}

/**
 * Sincronizza la matrice delle capabilities con l'infrastruttura WP_Roles di WordPress.
 *
 * @return void
 */
function dfn_sync_wp_roles(): void
{
    $roles_catalog  = dfn_get_stored_roles();
    $matrix         = dfn_get_stored_roles_matrix();
    $catalog_by_mod = dfn_get_activities_catalog();

    // Raccoglie tutte le capability registrate
    $all_caps = [];
    foreach ($catalog_by_mod as $mod_caps) {
        foreach (array_keys($mod_caps) as $cap) {
            $all_caps[] = $cap;
        }
    }

    // Assicura che i ruoli esistano in WP_Roles
    foreach ($roles_catalog as $role_slug => $role_info) {
        $wp_role = get_role($role_slug);
        if (! $wp_role) {
            // Registra il nuovo ruolo in WordPress
            add_role($role_slug, $role_info['label'], ['read' => true]);
            $wp_role = get_role($role_slug);
        }

        if (! $wp_role) {
            continue;
        }

        // Se è l'amministratore, assegna sempre tutte le capabilities
        if ($role_slug === 'administrator') {
            foreach ($all_caps as $cap) {
                $wp_role->add_cap($cap);
            }
            continue;
        }

        // Assegna o rimuove le singole capabilities in base alla matrice
        foreach ($all_caps as $cap) {
            $is_enabled = ! empty($matrix[$role_slug][$cap]);
            if ($is_enabled) {
                $wp_role->add_cap($cap);
            } else {
                $wp_role->remove_cap($cap);
            }
        }

        // Assicura la capability read per l'accesso base
        $wp_role->add_cap('read');
    }
}

/**
 * Helper per verificare se l'utente corrente ha una capability specifica.
 *
 * @param string   $capability Nome capability tecnica.
 * @param int|null $user_id    ID utente opzionale (default corrente).
 * @return bool
 */
function dfn_user_can(string $capability, ?int $user_id = null): bool
{
    $uid = ($user_id && $user_id > 0) ? $user_id : get_current_user_id();
    if (! $uid) {
        return false;
    }

    // Gli amministratori hanno sempre accesso
    if (user_can($uid, 'manage_options')) {
        return true;
    }

    // 1. Controllo standard capability WP
    if (user_can($uid, $capability)) {
        return true;
    }

    // 2. Controllo diretto tramite i ruoli FAI assegnati all'utente (user_meta + roles)
    $assigned_fai_roles = get_user_meta($uid, '_dfn_assigned_fai_roles', true);
    if (! is_array($assigned_fai_roles)) {
        $assigned_fai_roles = [];
    }

    $u_obj = get_userdata($uid);
    if ($u_obj) {
        $assigned_fai_roles = array_unique(array_merge($assigned_fai_roles, (array) $u_obj->roles));
    }

    if (! empty($assigned_fai_roles)) {
        $matrix = dfn_get_stored_roles_matrix();
        foreach ($assigned_fai_roles as $r_slug) {
            if (! empty($matrix[$r_slug][$capability])) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Verifica se l'utente ha accesso ad almeno un'attività del modulo specificato.
 *
 * @param string   $module  'prenotazioni' | 'convenzioni'
 * @param int|null $user_id ID utente opzionale
 * @return bool
 */
function dfn_user_has_module_access(string $module, ?int $user_id = null): bool
{
    if (dfn_user_can('manage_options', $user_id)) {
        return true;
    }

    $catalog = dfn_get_activities_catalog();
    if (! isset($catalog[$module])) {
        return false;
    }

    foreach (array_keys($catalog[$module]) as $cap) {
        if (dfn_user_can($cap, $user_id)) {
            return true;
        }
    }

    return false;
}

// Sincronizzazione automatica all'inizializzazione dell'admin se non ancora sincronizzato
add_action('admin_init', function () {
    if (! get_option('dfn_roles_synced_v2')) {
        dfn_sync_wp_roles();
        update_option('dfn_roles_synced_v2', '1');
    }
});

/**
 * Renderizza la sezione dedicata "Ruoli FAI Delegazione" nella schermata Modifica Utente (user-edit.php e profile.php).
 * Consente l'assegnazione simultanea di ruoli multipli FAI allo stesso utente.
 *
 * @param WP_User $user Utente WordPress in fase di modifica.
 */
function dfn_render_user_fai_roles_section(WP_User $user): void
{
    // Solo chi può gestire gli utenti può modificare i ruoli FAI
    if (! current_user_can('promote_users') && ! current_user_can('manage_options')) {
        return;
    }

    $stored_roles  = dfn_get_stored_roles();
    $user_roles    = (array) $user->roles;
    $fai_assigned  = get_user_meta($user->ID, '_dfn_assigned_fai_roles', true);
    if (! is_array($fai_assigned)) {
        $fai_assigned = [];
        // Se non ancora salvato in meta, controlliamo i ruoli nativi
        foreach (array_keys($stored_roles) as $s_role) {
            if (in_array($s_role, $user_roles, true)) {
                $fai_assigned[] = $s_role;
            }
        }
    }
    ?>
    <h2 style="color: #004b23; font-weight: 700; margin-top: 35px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
        🛡️ Ruoli &amp; Incarichi FAI Novara (Selezione Multipla)
    </h2>
    <table class="form-table" role="presentation" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; max-width: 950px; border-collapse: separate; border-spacing: 0; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <tr>
            <th scope="row" style="vertical-align: top; padding: 24px 20px 24px 24px; width: 260px; background: #f8fafc; border-right: 1px solid #f1f5f9;">
                <label style="font-weight: 700; color: #1e293b; font-size: 14px; display: block;">Incarichi FAI Assegnati</label>
                <p class="description" style="margin-top: 8px; font-weight: 400; color: #64748b; font-size: 13px; line-height: 1.5;">
                    Seleziona uno o più ruoli FAI.<br>L'utente erediterà tutte le funzionalità abilitate nella matrice per ciascun ruolo selezionato.
                </p>
            </th>
            <td style="padding: 24px 24px 24px 24px;">
                <?php wp_nonce_field('dfn_save_user_fai_roles_action', 'dfn_user_fai_roles_nonce'); ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($stored_roles as $r_slug => $r_data) : 
                        if ($r_slug === 'administrator') {
                            continue; // L'amministratore è gestito dal selettore principale di WordPress
                        }
                        $is_checked = in_array($r_slug, $fai_assigned, true) || in_array($r_slug, $user_roles, true);
                    ?>
                        <label style="display: flex; align-items: flex-start; gap: 14px; cursor: pointer; padding: 12px 16px; border-radius: 8px; border: 1px solid <?php echo $is_checked ? '#86efac' : '#e2e8f0'; ?>; background: <?php echo $is_checked ? '#f0fdf4' : '#ffffff'; ?>; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                            <input 
                                type="checkbox" 
                                name="dfn_assigned_fai_roles[]" 
                                value="<?php echo esc_attr($r_slug); ?>" 
                                <?php checked($is_checked, true); ?>
                                style="margin-top: 3px; accent-color: #004b23; width: 18px; height: 18px; flex-shrink: 0;"
                            />
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong style="color: #0f172a; font-size: 14px;"><?php echo esc_html($r_data['label']); ?></strong>
                                    <code style="color: #64748b; font-size: 11px; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html($r_slug); ?></code>
                                </div>
                                <div style="font-size: 12px; color: #64748b; margin-top: 3px; line-height: 1.4;">
                                    <?php echo esc_html($r_data['description']); ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'dfn_render_user_fai_roles_section');
add_action('edit_user_profile', 'dfn_render_user_fai_roles_section');

/**
 * Salva i ruoli multipli FAI assegnati all'utente al salvataggio del profilo.
 *
 * @param int $user_id ID dell'utente modificato.
 */
function dfn_save_user_fai_roles_section(int $user_id): void
{
    // Solo chi può gestire o modificare utenti
    if (! current_user_can('promote_users') && ! current_user_can('manage_options') && ! current_user_can('edit_users') && ! current_user_can('edit_user', $user_id)) {
        return;
    }

    if (! isset($_POST['dfn_user_fai_roles_nonce']) || ! wp_verify_nonce($_POST['dfn_user_fai_roles_nonce'], 'dfn_save_user_fai_roles_action')) {
        return;
    }

    $submitted_fai_roles = isset($_POST['dfn_assigned_fai_roles']) && is_array($_POST['dfn_assigned_fai_roles']) 
        ? array_map('sanitize_key', $_POST['dfn_assigned_fai_roles']) 
        : [];

    // Salva l'elenco esplicito degli incarichi FAI nei metadati utente
    update_user_meta($user_id, '_dfn_assigned_fai_roles', $submitted_fai_roles);

    // Sincronizza anche i ruoli secondari nell'oggetto WP_User
    $user = get_userdata($user_id);
    if ($user) {
        $all_fai_roles = array_keys(dfn_get_stored_roles());
        foreach ($all_fai_roles as $f_role) {
            if ($f_role === 'administrator') {
                continue;
            }

            if (in_array($f_role, $submitted_fai_roles, true)) {
                $user->add_role($f_role);
            } else {
                $user->remove_role($f_role);
            }
        }
    }
}
add_action('personal_options_update', 'dfn_save_user_fai_roles_section', 50);
add_action('edit_user_profile_update', 'dfn_save_user_fai_roles_section', 50);

