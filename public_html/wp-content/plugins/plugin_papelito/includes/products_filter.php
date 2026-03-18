<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read the user CEP from cookies safely.
 */
function papelito_cookie_cep() {
    if ( ! isset( $_COOKIE['user_cep'] ) ) {
        return null;
    }

    $cep = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_COOKIE['user_cep'] ) ) );

    return '' === $cep ? null : (int) $cep;
}

/**
 * Log debug messages only in development.
 */
function papelito_debug_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( wp_json_encode( [ 'papelito' => $message ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }
}
// Filtra a consulta baseada no CEP do usuário (do perfil ou cookie).
function custom_products_filter($query)
{
    if (
        !is_admin() && $query->is_main_query() &&
        (isset($query->query_vars['product_cat']) ||
            (isset($query->query_vars['post_type']) && $query->query_vars['post_type'] == 'product'))
    ) {
        $user_cep = null;
        $user_name = 'Usuário não logado';  // Nome padrão caso o usuário não esteja logado

        // Verificar se o usuário está logado e obter o CEP e o nome do usuário
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $user_name = get_user_meta($user_id, 'first_name', true); // Nome do usuário
            $user_cep = preg_replace('/[^0-9]/', '', get_user_meta($user_id, 'cep', true));

            if ( empty( $user_cep ) ) {
                $user_cep = papelito_cookie_cep();
            }
        } else {
            $user_cep = papelito_cookie_cep();
        }

        // Verificar se o CEP do usuário está definido
        if (!is_null($user_cep)) {
            $vendors_query_args = array(
                'role' => 'seller',
            );

            $vendors = get_users($vendors_query_args);
            $vendors_ids = array();

            // Log para depuração
            papelito_debug_log( 'User CEP: ' . $user_cep );

            foreach ($vendors as $vendor) {
                $min_ceps = get_user_meta($vendor->ID, 'min_cep');
                $max_ceps = get_user_meta($vendor->ID, 'max_cep');

                // Verificar se os CEPs mínimos e máximos são arrays
                if (is_array($min_ceps) && is_array($max_ceps)) {
                    for ($i = 0; $i < count($min_ceps); $i++) {
                        if ($min_ceps[$i] <= $user_cep && $max_ceps[$i] >= $user_cep) {
                            $vendors_ids[] = $vendor->ID;
                            // Log para depuração
                            papelito_debug_log( 'Vendor ID added: ' . $vendor->ID );
                            break;
                        }
                    }
                }
            }

            // Verificar se nenhum vendedor foi encontrado
            if (count($vendors_ids) == 0) {
                $vendors_ids = array(0);
                papelito_debug_log( 'No vendors found, setting vendors_ids to array(0)' );
            }

            // Log para depuração
            papelito_debug_log( 'Filtered Vendor IDs: ' . implode(',', $vendors_ids) );

            $query->set('author__in', $vendors_ids);
        } else {
            // Log para depuração quando o CEP é null
            papelito_debug_log( 'User CEP is null for user: ' . $user_name );
        }
    }
}
add_action('pre_get_posts', 'custom_products_filter');

function product_list_filter($query)
{
    $user_cep = papelito_cookie_cep();

    if (null !== $user_cep) {
        $vendors_query_args = array(
            'role' => 'seller',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'min_cep',
                    'value' => $user_cep,
                    'compare' => '<=',
                    'type' => 'NUMERIC'
                ),
                array(
                    'key' => 'max_cep',
                    'value' => $user_cep,
                    'compare' => '>=',
                    'type' => 'NUMERIC'
                )
            )
        );

        $vendors_ids = array_map(function ($user) {
            return $user->ID;
        }, get_users($vendors_query_args));


        $vendors_ids = array_unique($vendors_ids);

        // forces query to return no results
        if (count($vendors_ids) == 0) {
            $vendors_ids = array(0);
        }

        $query['author__in'] = $vendors_ids;

    }
    return $query;
}
add_filter('jet-woo-builder/shortcodes/jet-woo-products/query-args', 'product_list_filter');

function my_related_products_query_args($query_args, $product_id)
{
    // Get the author ID of the current product
    $author_id = absint( get_post_field('post_author', $product_id) );

    // Modify the query arguments to only return products of the same author
    $query_args['where'] .= " AND p.post_author = {$author_id}";

    return $query_args;
}
add_filter('woocommerce_product_related_posts_query', 'my_related_products_query_args', 10, 2);

function duplicate_products_for_vendor($user_id)
{
    try {
        $user = get_userdata($user_id);
        $user_roles = $user->roles;

        // Check if the new user is a seller
        if (in_array('seller', $user_roles)) {
            $admin_id = 0;
            $admins = get_users(array('role' => 'administrator')); // Get all admin users

            $products = [];
            
            foreach($admins as $admin) {
                $admin_id = $admin->ID;
                
                // Get all WooCommerce products belonging to the admin
                $args = array(
                    'post_type' => 'product',
                    'posts_per_page' => -1,
                    'author' => $admin_id
                );
                
                $products = get_posts($args);
                
                if (count($products) > 0) {
                    break;
                }
            }

            // Duplicate the products for the new vendor
            if ( ! class_exists( '\Dokan_SPMV_Product_Duplicator' ) ) {
                return;
            }

            $duplicator = new \Dokan_SPMV_Product_Duplicator();
            $product_title_counter = array();
            
            foreach ($products as $product) {
                if (in_array($product->post_title, $product_title_counter)) {
                    continue;
                }
                
                $id = $duplicator->clone_product($product->ID, $user_id);
                
                if (is_wp_error($id)) {
                    my_plugin_log_json($id);
                    my_plugin_log_json($product);
                    my_plugin_log_json($user_id);
                    my_plugin_log_json('\n\n');
                    continue;
                }
                
                array_push($product_title_counter, $product->post_title);
            }
        }
    } catch (\Throwable $ex) {
        my_plugin_log_json($ex);
    }
}
add_action('user_register', 'duplicate_products_for_vendor', 10, 1);
