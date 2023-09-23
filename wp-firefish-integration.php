<?php
/**
 * Plugin Name: WP FireFish Integration
 * Plugin URI: https://www.foxyhole.io
 * Description: Un plugin per integrare WordPress con FireFish.
 * Version: 1.0
 * Author: RedHunt07
 * Author URI: https://www.foxyhole.io/@redhunt07
 */

// Includere altri file
require_once(plugin_dir_path(__FILE__) . 'includes/firefish-api.php');
//require_once(plugin_dir_path(__FILE__) . 'includes/utility-functions.php');
require_once(plugin_dir_path(__FILE__) . 'admin/settings-page.php');

// Azioni e filtri di WordPress
add_action('admin_menu', 'wp_firefish_menu'); // Aggiungi la pagina di impostazioni
add_action('publish_post', 'wp_firefish_publish_post'); // Azione quando un post viene pubblicato
add_filter('get_avatar', 'wp_firefish_custom_avatar', 10, 6); // Aggiunge il filtro per modificare l'avatar con quello di firefish
add_filter('the_content', 'wp_foxyhole_add_federated_notice'); // Aggiunge il filtro per modificare il contenuto del post

function wp_firefish_custom_avatar($avatar, $id_or_email, $size, $default, $alt, $args) {
    // Ottieni l'ID del commento
    $comment_id = (is_object($id_or_email) && isset($id_or_email->comment_ID)) ? $id_or_email->comment_ID : null;

    if ($comment_id) {
        // Ottieni l'URL dell'avatar dal meta campo del commento
        $firefish_avatar_url = get_comment_meta($comment_id, 'firefish_avatar_url', true);

        // Se abbiamo un URL dell'avatar FireFish, utilizziamolo
        if ($firefish_avatar_url) {
            $avatar = "<img alt='{$alt}' src='{$firefish_avatar_url}' class='avatar avatar-{$size} photo' height='{$size}' width='{$size}' />";
        }
    }

    return $avatar;
}

// Funzione per aggiungere la nota "Federato - Powered by FoxyHole" alla fine di ogni post
function wp_foxyhole_add_federated_notice($content) {
    if (is_single()) {  // Verifica se è una singola pagina di post
        $federated_notice = '<p style="font-size: small; text-align: right;">';
        $federated_notice .= 'Federato - Powered by <a href="https://www.foxyhole.io" target="_blank">FoxyHole</a>';
        $federated_notice .= '</p>';
        
        $content .= $federated_notice;  // Aggiunge la nota alla fine del contenuto del post
    }
    
    return $content;  // Restituisce il contenuto modificato
}
