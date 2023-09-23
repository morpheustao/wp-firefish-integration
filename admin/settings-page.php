<?php
// Aggiungiamo una pagina di menu al pannello di amministrazione
function wp_firefish_menu() {
    add_menu_page(
        'WP firefish Settings',
        'WP firefish',
        'administrator',
        'wp_firefish',
        'wp_firefish_settings_page'
    );
}

// Mostra la pagina delle impostazioni
function wp_firefish_settings_page() {
    ?>
    <div class="wrap">
        <h2>WP firefish Integration</h2>
        
        <form method="post" action="options.php">
            <?php wp_nonce_field('update-options'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">FireFish API URL</th>
                    <td>
                        <input type="text" name="firefish_api_url" value="<?php echo esc_attr(get_option('firefish_api_url')); ?>" placeholder="https://your-instance.com/api" />
                        <p class="description">Inserisci l'URL base dell'API di FireFish. Ad esempio: https://your-instance.com/api</p>
                    </td>
                </tr>
                
                <tr valign="top">
                    <th scope="row">FireFish API Token</th>
                    <td>
                        <input type="text" name="firefish_api_token" value="<?php echo esc_attr(get_option('firefish_api_token')); ?>" placeholder="Your-API-Token-Here" />
                        <p class="description">Inserisci il token API fornito da FireFish.</p>
                    </td>
                </tr>
            </table>
            
            <input type="hidden" name="action" value="update" />
            <input type="hidden" name="page_options" value="firefish_api_url,firefish_api_token" />
            
            <p class="submit">
                <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
            </p>
        </form>
    </div>
    <?php
}

// Registra la funzione del menu di amministrazione
add_action('admin_menu', 'wp_firefish_menu');
