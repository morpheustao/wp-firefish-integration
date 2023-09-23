<?php

// Funzione per convertire il contenuto di WordPress in Misskey Flavored Markdown (MFM)
function convert_to_mfm($content) {
    $content = preg_replace('/<!--(.|\s)*?-->/', '', $content);
    $content = strip_tags($content); // Rimuove i tag HTML
    return $content;
}

// Funzione generica per eseguire richieste API
function execute_api_request($api_url, $api_token, $endpoint, $payload) {
    $args = array(
        'method' => 'POST',
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type' => 'application/json; charset=utf-8'
        ),
        'body' => json_encode($payload)
    );

    return wp_remote_post($api_url . $endpoint, $args);
}

// Funzione per inviare un post a FireFish
function wp_firefish_publish_post($post_id) {
    error_log("Sto lavorando sul post con ID: $post_id");

    $api_url = get_option('firefish_api_url');
    $api_token = get_option('firefish_api_token');

    if (empty($api_url) || empty($api_token)) {
        error_log("Configurazione FireFish mancante. Interrompo la pubblicazione.");
        return;
    }

    $post = get_post($post_id);
    $post_content = convert_to_mfm($post->post_content);
    $post_title = $post->post_title;

    $message = $post_title . "\n" . $post_content;

    if (empty(trim($message))) {
        error_log("Il messaggio è vuoto. Interrompo la pubblicazione.");
        return;
    }

    $payload = array('text' => $message);
    $response = execute_api_request($api_url, $api_token, '/notes/create', $payload);

    if (is_wp_error($response)) {
        error_log('Errore nella pubblicazione su FireFish: ' . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $note_id = $data['createdNote']['id'] ?? null;

    if ($note_id) {
        update_post_meta($post_id, 'firefish_note_id', $note_id);
        error_log('Meta campo aggiornato con successo.');
    } else {
        error_log('Nota non creata in FireFish. Risposta: ' . $body);
    }
}

add_action('publish_post', 'wp_firefish_publish_post');

// Rimuove tutte le menzioni dal testo (formato "@username")
function remove_mentions($text) {
    return preg_replace('/@\w+/', '', $text);
}

// Funzione per sincronizzare i commenti da FireFish
function wp_firefish_sync_comments($post_id) {
    $is_syncing = get_post_meta($post_id, 'is_syncing_comments', true);
    if ('yes' === $is_syncing) {
        return;
    }
    update_post_meta($post_id, 'is_syncing_comments', 'yes');
    $api_url = get_option('firefish_api_url');
    $api_token = get_option('firefish_api_token');
    
    $firefish_note_id = get_post_meta($post_id, 'firefish_note_id', true);
    
    if (empty($firefish_note_id)) {
        error_log("ID della nota FireFish mancante per il post $post_id");
        return;
    }

    $payload = array(
        'noteId' => $firefish_note_id,
        'limit' => 10 // puoi impostare altri parametri come sinceId, untilId ecc.
    );

    $response = execute_api_request($api_url, $api_token, '/notes/replies', $payload);
    
    if (is_wp_error($response)) {
        error_log('Errore nel recuperare i commenti da FireFish: ' . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $json_data = json_decode($body, true);

    if (json_last_error() === JSON_ERROR_NONE && !isset($json_data['error'])) {
        $comments_data = $json_data;

        foreach ($comments_data as $comment_data) {
            $firefish_comment_id = $comment_data['id'];
            
            // Verifica se il commento è già stato sincronizzato
            $existing_comments = get_comments(array(
                'meta_key' => 'firefish_comment_id',
                'meta_value' => $firefish_comment_id,
            ));
            
            if (count($existing_comments) > 0) {
                continue;
            }

            // Sanitizzazione dei dati del commento
            $comment_author = sanitize_text_field($comment_data['user']['name']);
            $comment_content = sanitize_textarea_field($comment_data['text']);
            $comment_content = remove_mentions($comment_content);  // Rimuove le menzioni
            $comment_date = sanitize_text_field($comment_data['createdAt']);
            $comment_avatar_url = esc_url($comment_data['user']['avatarUrl']);

            // Preparare i dati del commento
            $comment_data = array(
                'comment_post_ID' => $post_id,
                'comment_author' => $comment_author,
                'comment_content' => $comment_content,
                'comment_date' => $comment_date,
                'comment_approved' => 1,
            );

            // Inserire il commento nel database
            $comment_id = wp_insert_comment($comment_data);
            
            if ($comment_id) {
                add_comment_meta($comment_id, 'firefish_comment_id', $firefish_comment_id);
                add_comment_meta($comment_id, 'synchronized_from_firefish', 'yes');
                add_comment_meta($comment_id, 'firefish_avatar_url', $comment_avatar_url);
            } else {
                error_log("Errore nell'inserimento del commento FireFish con ID $firefish_comment_id.");
            }
        }
    } else {
        error_log('Errore API o JSON Decode Error');
    }
    delete_post_meta($post_id, 'is_syncing_comments');
}


// Hook per sincronizzare i commenti ogni volta che un post viene visualizzato
add_action('wp_head', function() {
    if (is_single()) {
        $post_id = get_the_ID();
        $last_sync = get_post_meta($post_id, 'last_firefish_sync', true);
        $current_time = current_time('timestamp');

        // Utilizzare un intervallo di tempo per determinare se sincronizzare o no
        $time_interval = 10; // 10 secondi
        if (!$last_sync || ($current_time - $last_sync) > $time_interval) {
            wp_firefish_sync_comments($post_id);
            update_post_meta($post_id, 'last_firefish_sync', $current_time);
        }
    }
});


// Funzione per inviare un commento a FireFish
function wp_firefish_publish_comment($comment_ID, $comment) {
    $post_id = $comment->comment_post_ID;
    $is_syncing = get_post_meta($post_id, 'is_syncing_comments', true);
    if ('yes' === $is_syncing) {
        return;
    }
    // Controllo se il commento è stato sincronizzato da FireFish
    $synchronized_from_firefish = get_comment_meta($comment_ID, 'synchronized_from_firefish', true);
    if ($synchronized_from_firefish === 'yes') {
        error_log("Il commento con ID $comment_ID è stato sincronizzato da FireFish. Ignorando...");
        return;
    }

    // Controllo per evitare duplicati
    $firefish_comment_id = get_comment_meta($comment_ID, 'firefish_comment_id', true);
    if (!empty($firefish_comment_id)) {
        error_log("Il commento con ID $comment_ID è già stato pubblicato su FireFish. Ignorando...");
        return;
    }

    // Ottenere le impostazioni dell'API
    $api_url = get_option('firefish_api_url');
    $api_token = get_option('firefish_api_token');

    // Ottenere i dettagli del commento e del post associato
    $comment_content = $comment->comment_content;
    $post_id = $comment->comment_post_ID;
    $firefish_note_id = get_post_meta($post_id, 'firefish_note_id', true);

     // Ottenere i dettagli del commento e del post associato
     $comment_content = $comment->comment_content;
     $post_id = $comment->comment_post_ID;
     $firefish_note_id = get_post_meta($post_id, 'firefish_note_id', true);
 
     // Ottieni l'ID del commento padre, se esiste
     $parent_comment_id = $comment->comment_parent;
 
     if ($parent_comment_id) {
         // Ottieni i dettagli del commento padre
         $parent_comment = get_comment($parent_comment_id);
         $parent_comment_author = $parent_comment->comment_author;
 
         // Prependi "@NomeUtenteFireFish" al contenuto del commento
         $comment_content = "@" . $parent_comment_author . " " . $comment_content;
     }

    // Verifica se l'ID della nota FireFish esiste per il post associato
    if (empty($firefish_note_id)) {
        error_log("ID della nota FireFish mancante per il post $post_id");
        return;
    }

    // Preparare il payload per l'API di FireFish
    $payload = array(
        'replyId' => $firefish_note_id,
        'text' => $comment_content
    );
    $args = array(
        'method' => 'POST',
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type' => 'application/json; charset=utf-8'
        ),
        'body' => json_encode($payload)
    );

    // Eseguire la richiesta API
    $response = wp_remote_post($api_url . '/notes/create', $args);

    if (is_wp_error($response)) {
        error_log('Errore nella pubblicazione del commento su FireFish: ' . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $note_id = $data['createdNote']['id'] ?? null;

    if ($note_id) {
        update_comment_meta($comment_ID, 'firefish_note_id', $note_id);
        error_log('Commento pubblicato con successo su FireFish con ID della nota: ' . $note_id);
    } else {
        error_log('Impossibile pubblicare il commento su FireFish. Risposta: ' . $body);
    }
}

// Aggiungi la funzione al hook wp_insert_comment
add_action('wp_insert_comment', 'wp_firefish_publish_comment', 99, 2);
