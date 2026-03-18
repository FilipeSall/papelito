<?php
/**
 * Plugin Name: Plugin Papelito
 * Description: Adiciona faixas de CEPs para distribuidores, filtragem de produtos com base no CEP do usuaário e novos campos de cadastro para clientes.
 * Version: 1.1.5
 * Author: Nuplan
 * License: GPL2
 */

defined( 'ABSPATH' ) || exit;

require_once(plugin_dir_path(__FILE__) . 'includes/user_registration.php');
require_once(plugin_dir_path(__FILE__) . 'includes/products_filter.php');

/**
 * Render the plugin nonce in profile forms.
 */
function papelito_profile_nonce_field() {
    wp_nonce_field( 'papelito_profile_fields', 'papelito_profile_fields_nonce' );
}

/**
 * Retrieve a sanitized post value.
 */
function papelito_posted_value( $key, $default = '' ) {
    if ( ! isset( $_POST[ $key ] ) ) {
        return $default;
    }

    return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
}

/**
 * Log plugin data outside the plugin directory.
 */
function my_plugin_log_json($data)
{
    $upload_dir = wp_upload_dir();

    if ( empty( $upload_dir['basedir'] ) ) {
        return;
    }

    $log_dir = trailingslashit( $upload_dir['basedir'] ) . 'papelito/logs';

    if ( ! wp_mkdir_p( $log_dir ) ) {
        return;
    }

    $json_string = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

    if ( false === $json_string ) {
        $json_string = wp_json_encode( [ 'message' => 'Falha ao serializar log.' ] );
    }

    file_put_contents( trailingslashit( $log_dir ) . 'plugin_papelito.log', $json_string . PHP_EOL, FILE_APPEND | LOCK_EX );
}

/**
 * Add custom fields to the user profile page for users with the "vendor" role.
 */
function vendor_profile_fields($user)
{
    if (in_array('seller', $user->roles)) {
        display_seller_CEP_form($user);
    } else if (in_array('customer', $user->roles)) {
        add_user_meta_fields($user);
    }
}
add_action('show_user_profile', 'vendor_profile_fields');
add_action('edit_user_profile', 'vendor_profile_fields');

// Add user meta fields to admin user edit page for customer role
function add_user_meta_fields($user)
{
    // Only show fields for customers
    $store_name = get_user_meta($user->ID, 'store_name', true);
    $phone_number = get_user_meta($user->ID, 'phone_number', true);
    $cnpj = get_user_meta($user->ID, 'cnpj', true);
    $instagram = get_user_meta($user->ID, 'instagram', true);
    $state = get_user_meta($user->ID, 'state', true);
    $city = get_user_meta($user->ID, 'city', true);
    $cep = get_user_meta($user->ID, 'cep', true);
    ?>
    <h3>
        <?php esc_html_e('Informações do cliente', 'text-domain'); ?>
    </h3>
    <?php papelito_profile_nonce_field(); ?>
    <table class="form-table">
        <tr>
            <th><label for="store_name">
                    <?php esc_html_e('Nome da loja', 'text-domain'); ?>
                </label></th>
            <td><input type="text" name="store_name" id="store_name" value="<?php echo esc_attr($store_name); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="phone_number">
                    <?php esc_html_e('Telefone', 'text-domain'); ?>
                </label></th>
            <td><input type="text" name="phone_number" id="phone_number" value="<?php echo esc_attr($phone_number); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="cnpj">
                    <?php esc_html_e('CNPJ', 'text-domain'); ?>
                </label></th>
            <td><input type="text" name="cnpj" id="cnpj" value="<?php echo esc_attr($cnpj); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="instagram">
                    <?php esc_html_e('Instagram', 'text-domain'); ?>
                </label></th>
            <td><input type="text" name="instagram" id="instagram" value="<?php echo esc_attr($instagram); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="state">
                    <?php esc_html_e('Estado', 'text-domain'); ?>
                </label></th>
            <td>
                <select name="state" id="state">
                    <?php foreach (brazilian_states as $value => $text): ?>
                        <?php if (empty($value))
                            continue; ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $state); ?>><?php echo esc_html($text); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="city">
                    <?php esc_html_e('Cidade', 'text-domain'); ?>
                </label></th>
            <td><input type="text" name="city" id="city" value="<?php echo esc_attr($city); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="cep">
                    <?php esc_html_e('CEP', 'text-domain'); ?>
                </label></th>
            <td><input type="text" name="cep" id="cep" value="<?php echo esc_attr($cep); ?>" class="regular-text" />
            </td>
        </tr>
    </table>
    <?php
}

function display_seller_CEP_form($user)
{
    $min_cep = get_user_meta($user->ID, 'min_cep', false);
    $max_cep = get_user_meta($user->ID, 'max_cep', false);
    $count = count($min_cep);
    ?>
    <h3>
        <?php esc_html_e('Vendor Information', 'vendor-profile-fields'); ?>
    </h3>
    <?php papelito_profile_nonce_field(); ?>

    <table class="form-table">
        <tr>
            <th><label for="vendor_ceps">
                    <?php esc_html_e('CEPs', 'vendor-profile-fields'); ?>
                </label></th>
            <td id="vendor_ceps">
                <?php if ($min_cep && is_array($min_cep) && $max_cep && is_array($max_cep)): ?>
                    <?php for ($i = 0; $i < $count; $i++): ?>
                        <div>
                            <input placeholder="CEP mínimo" type="text" name="vendor_min_ceps[]"
                                value="<?php echo esc_attr($min_cep[$i]); ?>" class="regular-text" />
                            <input placeholder="CEP máximo" type="text" name="vendor_max_ceps[]"
                                value="<?php echo esc_attr($max_cep[$i]); ?>" class="regular-text" />
                            <?php if ($i > 0): ?>
                                <button type="button" class="button remove-cep">
                                    <?php esc_html_e('Remover', 'vendor-profile-fields'); ?>
                                </button>
                            <?php endif; ?>
                            <br />
                        </div>
                    <?php endfor; ?>
                <?php else: ?>
                    <div>
                        <input placeholder="CEP mínimo" type="text" name="vendor_min_ceps[]" class="regular-text" />
                        <input placeholder="CEP máximo" type="text" name="vendor_max_ceps[]" class="regular-text" />
                        <br />
                    </div>
                <?php endif; ?>

                <button type="button" class="button" id="add-cep">
                    <?php esc_html_e('Adicionar faixa de CEP', 'vendor-profile-fields'); ?>
                </button>
            </td>
        </tr>
    </table>
    <?php
}

/**
Save custom fields when the user profile is updated.
*/
function save_vendor_profile_fields($user_id)
{
    if (
        ! current_user_can( 'edit_user', $user_id ) ||
        ! isset( $_POST['papelito_profile_fields_nonce'] ) ||
        ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['papelito_profile_fields_nonce'] ) ), 'papelito_profile_fields' )
    ) {
        return;
    }

    $user = get_userdata($user_id);
    if ( ! $user ) {
        return;
    }

    if ( in_array( 'seller', $user->roles, true ) && isset( $_POST['vendor_min_ceps'], $_POST['vendor_max_ceps'] ) ) {
        delete_user_meta($user_id, "min_cep");
        delete_user_meta($user_id, "max_cep");

        $min_ceps = array_map(
            static function ( $value ) {
                return preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $value ) ) );
            },
            (array) wp_unslash( $_POST['vendor_min_ceps'] )
        );
        $max_ceps = array_map(
            static function ( $value ) {
                return preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $value ) ) );
            },
            (array) wp_unslash( $_POST['vendor_max_ceps'] )
        );
        $count = min( count( $min_ceps ), count( $max_ceps ) );

        for ($i = 0; $i < $count; $i++) {
            if ( '' === $min_ceps[ $i ] || '' === $max_ceps[ $i ] ) {
                continue;
            }

            add_user_meta($user_id, 'min_cep', $min_ceps[$i], false);
            add_user_meta($user_id, 'max_cep', $max_ceps[$i], false);
        }

        return;
    }

    if ( in_array( 'customer', $user->roles, true ) ) {
        if (isset($_POST['store_name'])) {
            update_user_meta($user_id, 'store_name', papelito_posted_value('store_name'));
        }

        if (isset($_POST['phone_number'])) {
            update_user_meta($user_id, 'phone_number', papelito_posted_value('phone_number'));
        }

        if (isset($_POST['cnpj'])) {
            update_user_meta($user_id, 'cnpj', papelito_posted_value('cnpj'));
        }

        if (isset($_POST['instagram'])) {
            update_user_meta($user_id, 'instagram', papelito_posted_value('instagram'));
        }

        if (isset($_POST['state'])) {
            update_user_meta($user_id, 'state', papelito_posted_value('state'));
        }

        if (isset($_POST['city'])) {
            update_user_meta($user_id, 'city', papelito_posted_value('city'));
        }

        if (isset($_POST['cep'])) {
            update_user_meta($user_id, 'cep', papelito_posted_value('cep'));
        }
    }
}
add_action('personal_options_update', 'save_vendor_profile_fields');
add_action('edit_user_profile_update', 'save_vendor_profile_fields');

function my_elementor_form_submit_handler($record, $ajax_handler)
{
    // Check if this is the CEP form
    if ($record->get_form_settings('form_name') === 'CEP') {
        // Get the user CEP from the form data
        $fields = $record->get_field([
            'id' => 'user_cep'
        ]);

        $user_cep = current($fields);
        $cookie_value = preg_replace( '/\D+/', '', sanitize_text_field( $user_cep['value'] ?? '' ) );

        // Set a cookie with the user CEP
        if ( '' !== $cookie_value ) {
            setcookie(
                'user_cep',
                $cookie_value,
                [
                    'expires'  => time() + ( 86400 * 30 ),
                    'path'     => COOKIEPATH ? COOKIEPATH : '/',
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]
            );
        }
    }
}
add_action('elementor_pro/forms/validation', 'my_elementor_form_submit_handler', 10, 2);

function vendor_profile_fields_scripts()
{
    wp_enqueue_script('jquery-mask', 'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js', array(), '1.14.16', true);
    wp_enqueue_script('vendor-profile-fields', plugin_dir_url(__FILE__) . 'js/vendor_profile_fields.js', array('jquery-mask'), '1.1.5', true);
}
add_action('admin_enqueue_scripts', 'vendor_profile_fields_scripts');
