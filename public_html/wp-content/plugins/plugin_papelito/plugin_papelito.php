<?php
/**
 * Plugin Name: Plugin Papelito
 * Description: Adiciona faixas de CEPs para distribuidores, filtragem de produtos com base no CEP do usuaário e novos campos de cadastro para clientes.
 * Version: 1.2.0
 * Author: Nuplan
 * License: GPL2
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/support.php';
require_once(plugin_dir_path(__FILE__) . 'includes/user_registration.php');
require_once(plugin_dir_path(__FILE__) . 'includes/products_filter.php');
require_once __DIR__ . '/includes/rest_api.php';
require_once __DIR__ . '/includes/auth_endpoints.php';
require_once __DIR__ . '/includes/revendedor_application.php';
require_once __DIR__ . '/includes/vendor_interests.php';
require_once __DIR__ . '/includes/favorites.php';
require_once __DIR__ . '/includes/catalog-pdf.php';
require_once __DIR__ . '/includes/flash_sale.php';
require_once __DIR__ . '/includes/home_assets.php';
require_once __DIR__ . '/includes/media_uploads.php';
require_once __DIR__ . '/includes/admin_reports.php';
require_once __DIR__ . '/includes/admin_users.php';
require_once __DIR__ . '/includes/shipping.php';
require_once __DIR__ . '/includes/correios_prepostage.php';
require_once __DIR__ . '/includes/vendor_geo.php';
require_once __DIR__ . '/includes/vendor_stock.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/correios_tracking.php';
require_once __DIR__ . '/includes/active_vendor.php';
require_once __DIR__ . '/includes/coupons.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/pagarme_client.php';
require_once __DIR__ . '/includes/pagarme_recipients.php';
require_once __DIR__ . '/includes/pagarme_payments.php';
require_once __DIR__ . '/includes/pagarme_webhook.php';
require_once __DIR__ . '/includes/pagarme_simulator.php';
require_once __DIR__ . '/includes/order_routing.php';
require_once __DIR__ . '/includes/vendor_dashboard.php';
require_once __DIR__ . '/includes/order_receipt.php';
require_once __DIR__ . '/includes/vendor_messaging.php';
require_once __DIR__ . '/includes/vendor_processing_alerts.php';
require_once __DIR__ . '/includes/company_flags.php';
require_once __DIR__ . '/includes/cnpj_validation.php';
require_once __DIR__ . '/includes/customer_identity.php';
require_once __DIR__ . '/includes/company_schema.php';
require_once __DIR__ . '/includes/company_onboarding.php';
require_once __DIR__ . '/includes/company_repository.php';
require_once __DIR__ . '/includes/cnpj_providers.php';
require_once __DIR__ . '/includes/company_idempotency.php';
require_once __DIR__ . '/includes/company_authz.php';
require_once __DIR__ . '/includes/company_services.php';
require_once __DIR__ . '/includes/company_owner_applications.php';
require_once __DIR__ . '/includes/company_active_context.php';
require_once __DIR__ . '/includes/legacy_migration.php';
require_once __DIR__ . '/includes/company_membership_services.php';
require_once __DIR__ . '/includes/company_invitation_services.php';
require_once __DIR__ . '/includes/company_access_request_services.php';
require_once __DIR__ . '/includes/company_endpoints.php';
require_once __DIR__ . '/includes/company_admin_endpoints.php';
require_once __DIR__ . '/includes/company_management_endpoints.php';
require_once __DIR__ . '/includes/company_final_check.php';

if ( ! defined( 'PAPELITO_DB_VERSION' ) ) {
	define( 'PAPELITO_DB_VERSION', '1.19.0' );
}

/**
 * Roda dbDelta das tabelas custom quando a versão grava no DB difere
 * da constante PAPELITO_DB_VERSION. Funciona em deploy por sync de
 * arquivos (Hostinger), onde register_activation_hook não dispara.
 */
function papelito_maybe_migrate_db() {
	$current = get_option( 'papelito_db_version', '0' );

	if ( version_compare( $current, PAPELITO_DB_VERSION, '>=' ) ) {
		return;
	}

	if ( function_exists( 'papelito_vendor_stock_install_tables' ) ) {
		papelito_vendor_stock_install_tables();
	}

	if ( function_exists( 'papelito_notifications_install_tables' ) ) {
		papelito_notifications_install_tables();
	}

	if ( function_exists( 'papelito_tracking_install_tables' ) ) {
		papelito_tracking_install_tables();
	}

	if ( function_exists( 'papelito_messaging_install_tables' ) ) {
		papelito_messaging_install_tables();
	}

	if ( function_exists( 'papelito_vendor_interests_install_table' ) ) {
		papelito_vendor_interests_install_table();
	}

	if ( function_exists( 'papelito_vendor_interests_backfill_legacy' ) ) {
		papelito_vendor_interests_backfill_legacy();
	}

	if ( function_exists( 'papelito_company_install_tables' ) ) {
		papelito_company_install_tables();
	}

	update_option( 'papelito_db_version', PAPELITO_DB_VERSION, true );
}
add_action( 'plugins_loaded', 'papelito_maybe_migrate_db', 5 );
register_activation_hook( __FILE__, 'papelito_maybe_migrate_db' );

/**
 * Render the plugin nonce in profile forms.
 */
function papelito_profile_nonce_field() {
    wp_nonce_field( 'papelito_profile_fields', 'papelito_profile_fields_nonce' );
}

/**
 * Retrieve a sanitized post value.
 */
function papelito_posted_value( string $key, string $default = '' ): string {
    if ( ! isset( $_POST[ $key ] ) ) {
        return $default;
    }

    return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
}

/**
 * Return whether the current authenticated user has the seller role.
 */
function papelito_current_user_is_seller(): bool {
	$user = wp_get_current_user();

	return $user instanceof WP_User && papelito_user_is_effective_seller( $user );
}

/**
 * Return the shared purchase-blocked message for sellers.
 */
function papelito_seller_purchase_block_message(): string {
	return 'Vendors nao compram pela plataforma.';
}

add_filter(
	'woocommerce_add_to_cart_validation',
	static function ( $passed ) {
		if ( ! papelito_current_user_is_seller() ) {
			return $passed;
		}

		wc_add_notice( papelito_seller_purchase_block_message(), 'error' );

		return false;
	},
	10,
	1
);

add_action(
	'woocommerce_checkout_process',
	static function () {
		if ( ! papelito_current_user_is_seller() ) {
			return;
		}

		wc_add_notice( papelito_seller_purchase_block_message(), 'error' );
	}
);

/**
 * Log plugin data outside the plugin directory.
 */
function my_plugin_log_json( array $data ): void
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
 * Check whether a REST route should be logged for products debugging.
 */
function papelito_should_log_products_rest_route( string $route ): bool {
    if ( ! is_string( $route ) || '' === $route ) {
        return false;
    }

    return 0 === strpos( $route, '/wc/v3/products' ) || 0 === strpos( $route, '/wc/v3/products/categories' );
}

/**
 * Normalize REST params for logs.
 */
function papelito_rest_log_params( array $params ): array {
    if ( ! is_array( $params ) ) {
        return array();
    }

    $keys = array( 'search', 'category', 'status', 'stock_status', 'page', 'per_page', 'orderby', 'order' );
    $normalized = array();

    foreach ( $keys as $key ) {
        if ( array_key_exists( $key, $params ) ) {
            $normalized[ $key ] = $params[ $key ];
        }
    }

    return $normalized;
}

add_filter(
    'rest_request_before_callbacks',
    function( $response, $handler, $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return $response;
        }

        $route = $request->get_route();

        if ( ! papelito_should_log_products_rest_route( $route ) ) {
            return $response;
        }

        my_plugin_log_json(
            array(
                'timestamp' => gmdate( 'c' ),
                'source'    => 'wordpress',
                'stage'     => 'rest_request_before_callbacks',
                'method'    => $request->get_method(),
                'route'     => $route,
                'params'    => papelito_rest_log_params( $request->get_params() ),
            )
        );

        return $response;
    },
    10,
    3
);

add_filter(
    'rest_post_dispatch',
    function( $result, $server, $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return $result;
        }

        $route = $request->get_route();

        if ( ! papelito_should_log_products_rest_route( $route ) ) {
            return $result;
        }

        $status = 200;
        $data   = null;

        if ( is_wp_error( $result ) ) {
            $status = 500;
            $data   = $result->get_error_messages();
        } elseif ( $result instanceof WP_HTTP_Response ) {
            $status = $result->get_status();
            $data   = $result->get_data();
        }

        $item_count = is_array( $data ) ? count( $data ) : null;
        $sample_ids = array();

        if ( is_array( $data ) ) {
            foreach ( array_slice( $data, 0, 10 ) as $item ) {
                if ( is_array( $item ) && isset( $item['id'] ) ) {
                    $sample_ids[] = $item['id'];
                }
            }
        }

        my_plugin_log_json(
            array(
                'timestamp'  => gmdate( 'c' ),
                'source'     => 'wordpress',
                'stage'      => 'rest_post_dispatch',
                'method'     => $request->get_method(),
                'route'      => $route,
                'params'     => papelito_rest_log_params( $request->get_params() ),
                'status'     => $status,
                'item_count' => $item_count,
                'sample_ids' => $sample_ids,
            )
        );

        return $result;
    },
    10,
    3
);

/**
 * Add custom fields to the user profile page for users with the "vendor" role.
 */
function vendor_profile_fields( WP_User $user ): void
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
function add_user_meta_fields( WP_User $user ): void
{
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
                    <?php foreach ( papelito_brazilian_states() as $value => $text ) : ?>
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

function display_seller_CEP_form( WP_User $user ): void
{
    $store_name = get_user_meta($user->ID, 'store_name', true);
    $phone_number = get_user_meta($user->ID, 'phone_number', true);
    $cnpj = get_user_meta($user->ID, 'cnpj', true);
    $instagram = get_user_meta($user->ID, 'instagram', true);
    $state = get_user_meta($user->ID, 'state', true);
    $city = get_user_meta($user->ID, 'city', true);
    $cep = get_user_meta($user->ID, 'cep', true);
    $discovery_channel = get_user_meta($user->ID, 'discovery_channel', true);
    $has_sold_papelito = get_user_meta($user->ID, 'has_sold_papelito', true);
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
            <th><label for="store_name">
                    <?php esc_html_e('Nome da loja', 'vendor-profile-fields'); ?>
                </label></th>
            <td><input type="text" name="store_name" id="store_name" value="<?php echo esc_attr($store_name); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="phone_number">
                    <?php esc_html_e('Telefone', 'vendor-profile-fields'); ?>
                </label></th>
            <td><input type="text" name="phone_number" id="phone_number" value="<?php echo esc_attr($phone_number); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="cnpj">
                    <?php esc_html_e('CNPJ', 'vendor-profile-fields'); ?>
                </label></th>
            <td><input type="text" name="cnpj" id="cnpj" value="<?php echo esc_attr($cnpj); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="instagram">
                    <?php esc_html_e('Instagram', 'vendor-profile-fields'); ?>
                </label></th>
            <td><input type="text" name="instagram" id="instagram" value="<?php echo esc_attr($instagram); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="state">
                    <?php esc_html_e('Estado', 'vendor-profile-fields'); ?>
                </label></th>
            <td>
                <select name="state" id="state">
                    <?php foreach ( papelito_brazilian_states() as $value => $text ) : ?>
                        <?php if (empty($value))
                            continue; ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $state); ?>><?php echo esc_html($text); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="city">
                    <?php esc_html_e('Cidade', 'vendor-profile-fields'); ?>
                </label></th>
            <td><input type="text" name="city" id="city" value="<?php echo esc_attr($city); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="cep">
                    <?php esc_html_e('CEP da loja', 'vendor-profile-fields'); ?>
                </label></th>
            <td><input type="text" name="cep" id="cep" value="<?php echo esc_attr($cep); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="discovery_channel">
                    <?php esc_html_e('Origem do contato', 'vendor-profile-fields'); ?>
                </label></th>
            <td><input type="text" name="discovery_channel" id="discovery_channel" value="<?php echo esc_attr($discovery_channel); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="has_sold_papelito">
                    <?php esc_html_e('Já vende Papelito?', 'vendor-profile-fields'); ?>
                </label></th>
            <td><input type="text" name="has_sold_papelito" id="has_sold_papelito" value="<?php echo esc_attr($has_sold_papelito); ?>"
                    class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="vendor_ceps">
                    <?php esc_html_e('Faixas de CEP', 'vendor-profile-fields'); ?>
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
function save_vendor_profile_fields( int $user_id ): void
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

    if ( in_array( 'seller', $user->roles, true ) || in_array( 'customer', $user->roles, true ) ) {
        $shared_keys = array( 'store_name', 'phone_number', 'cnpj', 'instagram', 'state', 'city', 'cep' );

        foreach ( $shared_keys as $meta_key ) {
            if ( isset( $_POST[ $meta_key ] ) ) {
                update_user_meta( $user_id, $meta_key, papelito_posted_value( $meta_key ) );
            }
        }
    }

    if ( in_array( 'seller', $user->roles, true ) ) {
        if ( isset( $_POST['discovery_channel'] ) ) {
            update_user_meta( $user_id, 'discovery_channel', papelito_posted_value( 'discovery_channel' ) );
        }

        if ( isset( $_POST['has_sold_papelito'] ) ) {
            update_user_meta( $user_id, 'has_sold_papelito', papelito_posted_value( 'has_sold_papelito' ) );
        }

        if ( isset( $_POST['vendor_min_ceps'], $_POST['vendor_max_ceps'] ) ) {
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
        }
    }
}
add_action('personal_options_update', 'save_vendor_profile_fields');
add_action('edit_user_profile_update', 'save_vendor_profile_fields');

function my_elementor_form_submit_handler( \ElementorPro\Modules\Forms\Classes\Form_Record $record, $ajax_handler )
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

/**
 * Enqueue frontend marketplace fixes.
 */
function papelito_enqueue_frontend_styles() {
    if ( ! is_page( array( 289, 'lojaparceiro' ) ) ) {
        return;
    }

    $style_path = plugin_dir_path( __FILE__ ) . 'css/frontend.css';

    wp_enqueue_style(
        'papelito-frontend',
        plugin_dir_url( __FILE__ ) . 'css/frontend.css',
        array(),
        file_exists( $style_path ) ? (string) filemtime( $style_path ) : '1.1.5'
    );
}
add_action( 'wp_enqueue_scripts', 'papelito_enqueue_frontend_styles', 100 );
