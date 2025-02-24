<?php
/**
 * Plugin Name: Construct 3 Game Uploader
 * Description: Permite subir juegos hechos en Construct 3 y mostrarlos mediante un shortcode.
 * Version: 1.0
 * Author: Kong DevStudios
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Crear la tabla en la base de datos para almacenar los juegos
function c3gu_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'construct3_games';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        folder_name VARCHAR(255) NOT NULL,
        shortcode VARCHAR(255) NOT NULL
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'c3gu_create_table');

// Agregar menú en el admin de WordPress
function c3gu_add_admin_menu() {
    add_menu_page(
        'Construct 3 Games',
        'Juegos Construct 3',
        'manage_options',
        'c3gu_game_manager',
        'c3gu_game_manager_page',
        'dashicons-games',
        6
    );
}
add_action('admin_menu', 'c3gu_add_admin_menu');

// Manejar la subida de archivos ZIP y extracción
function c3gu_handle_file_upload() {
    if (isset($_POST['submit_game']) && !empty($_FILES['game_zip']['name'])) {
        $uploaded_file = $_FILES['game_zip'];
        $upload_dir = wp_upload_dir();
        $game_dir = $upload_dir['basedir'] . '/construct3_games/';
        
        if (!file_exists($game_dir)) {
            mkdir($game_dir, 0755, true);
        }

        $zip_path = $game_dir . basename($uploaded_file['name']);
        move_uploaded_file($uploaded_file['tmp_name'], $zip_path);

        $zip = new ZipArchive;
        if ($zip->open($zip_path) === TRUE) {
            $folder_name = pathinfo($zip_path, PATHINFO_FILENAME);
            $extract_path = $game_dir . $folder_name;
            
            if (!file_exists($extract_path)) {
                mkdir($extract_path, 0755, true);
            }
            
            $zip->extractTo($extract_path);
            $zip->close();
            unlink($zip_path);

            global $wpdb;
            $table_name = $wpdb->prefix . 'construct3_games';
            $wpdb->insert($table_name, [
                'title' => sanitize_text_field($_POST['game_title']),
                'folder_name' => $folder_name,
                'shortcode' => '' // Se actualizará después con el ID real
            ]);
            
            $game_id = $wpdb->insert_id;
            $shortcode = '[mostrar_juego id="' . esc_attr($game_id) . '"]';
            $wpdb->update($table_name, ['shortcode' => $shortcode], ['id' => $game_id]);
        }
    }
}
add_action('admin_post_c3gu_upload_game', 'c3gu_handle_file_upload');

// Página de administración para ver los juegos subidos y sus shortcodes
function c3gu_game_manager_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'construct3_games';
    $games = $wpdb->get_results("SELECT * FROM $table_name");
    ?>
    <div class="wrap">
        <h1>Juegos Subidos</h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="c3gu_upload_game">
            <input type="text" name="game_title" placeholder="Título del juego" required>
            <input type="file" name="game_zip" accept=".zip" required>
            <input type="submit" name="submit_game" value="Subir Juego" class="button button-primary">
        </form>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Shortcode</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($games as $game) : ?>
                    <tr>
                        <td><?php echo esc_html($game->title); ?></td>
                        <td><code><?php echo esc_html($game->shortcode); ?></code></td>
                        <td><a href="#" class="button">Eliminar</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Shortcode para mostrar un juego con opción de pantalla completa
function c3gu_display_game($atts) {
    global $wpdb;
    $atts = shortcode_atts(['id' => ''], $atts);
    $game_id = intval($atts['id']);
    $table_name = $wpdb->prefix . 'construct3_games';
    $game = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $game_id));

    if (!$game) return "Juego no encontrado.";

    $game_url = content_url("/uploads/construct3_games/{$game->folder_name}/index.html");
    
    ob_start(); ?>
    <div style="position: relative; width: 100%; height: 600px;">
        <iframe id="c3game-<?php echo esc_attr($game_id); ?>" src="<?php echo esc_url($game_url); ?>" width="100%" height="100%" style="border:none;"></iframe>
        <button onclick="toggleFullscreen('c3game-<?php echo esc_attr($game_id); ?>')" style="position: absolute; bottom: 10px; right: 10px; padding: 8px 12px; background: black; color: white; border: none; cursor: pointer;">Pantalla Completa</button>
    </div>
    <script>
        function toggleFullscreen(id) {
            let elem = document.getElementById(id);
            if (!document.fullscreenElement) {
                if (elem.requestFullscreen) {
                    elem.requestFullscreen();
                } else if (elem.mozRequestFullScreen) {
                    elem.mozRequestFullScreen();
                } else if (elem.webkitRequestFullscreen) {
                    elem.webkitRequestFullscreen();
                } else if (elem.msRequestFullscreen) {
                    elem.msRequestFullscreen();
                }
            } else {
                document.exitFullscreen();
            }
        }
    </script>
    <?php return ob_get_clean();
}
add_shortcode('mostrar_juego', 'c3gu_display_game');
