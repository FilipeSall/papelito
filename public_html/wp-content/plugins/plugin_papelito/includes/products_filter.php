<?php

defined('ABSPATH') || exit;

/**
 * Read the user CEP from cookies safely.
 */
function papelito_cookie_cep()
{
    if (! isset($_COOKIE['user_cep'])) {
        return null;
    }

    $cep = preg_replace('/\D+/', '', sanitize_text_field(wp_unslash($_COOKIE['user_cep'])));

    return '' === $cep ? null : (int) $cep;
}

/**
 * Log debug messages only in development.
 */
function papelito_debug_log($message)
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(wp_json_encode(['papelito' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

/**
 * Only customer-facing sessions should have the catalog filtered by CEP.
 */
function papelito_should_filter_catalog_for_current_user()
{
    if (! is_user_logged_in()) {
        return true;
    }

    $user = wp_get_current_user();

    if (! $user || empty($user->roles)) {
        return true;
    }

    return (bool) array_intersect(array('customer', 'seller'), (array) $user->roles);
}

/**
 * Resolve the current CEP used for catalog filtering.
 */
function papelito_catalog_filter_cep()
{
    if (! papelito_should_filter_catalog_for_current_user()) {
        return null;
    }

    $user_cep = null;

    if (is_user_logged_in()) {
        $user_cep = preg_replace('/\D+/', '', (string) get_user_meta(get_current_user_id(), 'cep', true));
    }

    if (empty($user_cep)) {
        $user_cep = papelito_cookie_cep();
    }

    return empty($user_cep) ? null : (int) $user_cep;
}

/**
 * Find sellers that match a given CEP.
 *
 * @param int $user_cep CEP normalized to digits.
 * @return int[]
 */
function papelito_matching_vendor_ids($user_cep)
{
    $vendors     = get_users(
        array(
            'role' => 'seller',
        )
    );
    $vendors_ids = array();

    foreach ($vendors as $vendor) {
        $min_ceps = get_user_meta($vendor->ID, 'min_cep');
        $max_ceps = get_user_meta($vendor->ID, 'max_cep');

        if (! is_array($min_ceps) || ! is_array($max_ceps)) {
            continue;
        }

        $count = min(count($min_ceps), count($max_ceps));

        for ($i = 0; $i < $count; $i++) {
            $min_cep = (int) preg_replace('/\D+/', '', (string) $min_ceps[$i]);
            $max_cep = (int) preg_replace('/\D+/', '', (string) $max_ceps[$i]);

            if ($min_cep <= $user_cep && $max_cep >= $user_cep) {
                $vendors_ids[] = (int) $vendor->ID;
                break;
            }
        }
    }

    return array_values(array_unique(array_filter(array_map('absint', $vendors_ids))));
}

/**
 * Detect requests that should behave as the configured WooCommerce shop archive.
 *
 * Some production restores leave the shop page resolving as a singular page
 * query, which breaks Elementor's archive products widget. Match the configured
 * shop page early and normalize it back to the product archive query.
 *
 * @param WP_Query $query Main frontend query.
 * @return bool
 */
function papelito_is_shop_page_request($query)
{
    if (! $query instanceof WP_Query || is_admin() || ! $query->is_main_query()) {
        return false;
    }

    if (! function_exists('wc_get_page_id')) {
        return false;
    }

    $shop_page_id = absint(wc_get_page_id('shop'));

    if ($shop_page_id <= 0) {
        return false;
    }

    if (absint($query->get('page_id')) === $shop_page_id) {
        return true;
    }

    $shop_page = get_post($shop_page_id);

    if (! $shop_page instanceof WP_Post) {
        return false;
    }

    $requested_slugs = array_filter(
        array(
            (string) $query->get('pagename'),
            (string) $query->get('name'),
        )
    );

    if (in_array($shop_page->post_name, $requested_slugs, true)) {
        return true;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';

    if ('' === $request_uri) {
        return false;
    }

    $request_path = wp_parse_url(home_url(strtok($request_uri, '?')), PHP_URL_PATH);
    $shop_path    = wp_parse_url(get_permalink($shop_page_id), PHP_URL_PATH);

    if (! is_string($request_path) || ! is_string($shop_path)) {
        return false;
    }

    return untrailingslashit($request_path) === untrailingslashit($shop_path);
}

/**
 * Recover the main query when the Woo shop page falls back to a singular page.
 *
 * @param WP_Query $query Main frontend query.
 * @return void
 */
function papelito_force_shop_archive_query($query)
{
    if (! papelito_is_shop_page_request($query)) {
        return;
    }

    $query->set('post_type', 'product');
    $query->set('post_status', 'publish');
    $query->set('page_id', 0);
    $query->set('pagename', '');
    $query->set('name', '');

    unset($query->query['page_id'], $query->query['pagename'], $query->query['name']);
    unset($query->query_vars['page_id'], $query->query_vars['pagename'], $query->query_vars['name']);

    $query->is_page              = false;
    $query->is_singular          = false;
    $query->is_archive           = true;
    $query->is_post_type_archive = true;
}
add_action('pre_get_posts', 'papelito_force_shop_archive_query', 5);
// Filtra a consulta baseada no CEP do usuário (do perfil ou cookie).
function custom_products_filter($query)
{
    if (
        !is_admin() && $query->is_main_query() &&
        (isset($query->query_vars['product_cat']) ||
            (isset($query->query_vars['post_type']) && $query->query_vars['post_type'] == 'product'))
    ) {
        $user_name = 'Usuário não logado';  // Nome padrão caso o usuário não esteja logado

        if (is_user_logged_in()) {
            $user_name = get_user_meta(get_current_user_id(), 'first_name', true);
        }

        $user_cep = papelito_catalog_filter_cep();

        // Verificar se o CEP do usuÃ¡rio estÃ¡ definido
        if (!is_null($user_cep)) {
            // Log para depuraÃ§Ã£o
            papelito_debug_log('User CEP: ' . $user_cep);

            $vendors_ids = papelito_matching_vendor_ids($user_cep);

            // Verificar se nenhum vendedor foi encontrado
            if (count($vendors_ids) == 0) {
                papelito_debug_log('No vendors found, keeping default catalog query.');
                return;
            }

            // Log para depuraÃ§Ã£o
            papelito_debug_log('Filtered Vendor IDs: ' . implode(',', $vendors_ids));

            $query->set('author__in', $vendors_ids);
        } else {
            // Log para depuraÃ§Ã£o quando o CEP Ã© null
            papelito_debug_log('User CEP is null for user: ' . $user_name);
        }
    }
}
add_action('pre_get_posts', 'custom_products_filter');

function product_list_filter($query)
{
    $user_cep = papelito_catalog_filter_cep();

    if (null !== $user_cep) {
        $vendors_ids = papelito_matching_vendor_ids($user_cep);

        // Keep the catalog available if the CEP metadata is missing or stale.
        if (count($vendors_ids) == 0) {
            unset($query['author__in']);
            return $query;
        }

        $query['author__in'] = $vendors_ids;
    }
    return $query;
}
add_filter('jet-woo-builder/shortcodes/jet-woo-products/query-args', 'product_list_filter');

/**
 * Build a fallback product loop for the shop page when Elementor receives an empty query.
 *
 * @return string
 */
function papelito_render_shop_products_loop_fallback()
{
    if (! function_exists('WC') || ! WC()->query) {
        return '';
    }

    $paged = max(
        1,
        absint(get_query_var('paged')),
        absint(get_query_var('page')),
        isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 0
    );

    $per_page = (int) apply_filters(
        'loop_shop_per_page',
        wc_get_default_products_per_row() * wc_get_default_product_rows_per_page()
    );

    if ($per_page <= 0) {
        $per_page = 16;
    } else {
        $per_page = min(24, $per_page);
    }

    $ordering = WC()->query->get_catalog_ordering_args();
    $args     = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'paged'          => $paged,
        'posts_per_page' => $per_page,
        'meta_query'     => WC()->query->get_meta_query(),
        'tax_query'      => WC()->query->get_tax_query(),
    );

    if (! empty($ordering['orderby'])) {
        $args['orderby'] = $ordering['orderby'];
    }

    if (! empty($ordering['order'])) {
        $args['order'] = $ordering['order'];
    }

    if (! empty($ordering['meta_key'])) {
        $args['meta_key'] = $ordering['meta_key'];
    }

    $user_cep = papelito_catalog_filter_cep();

    if (null !== $user_cep) {
        $vendors_ids = papelito_matching_vendor_ids($user_cep);

        if (! empty($vendors_ids)) {
            $args['author__in'] = $vendors_ids;
        }
    }

    $products = new WP_Query($args);

    if (! $products->have_posts()) {
        wp_reset_postdata();
        return '';
    }

    $previous_query = $GLOBALS['wp_query'];
    $GLOBALS['wp_query'] = $products;

    wc_set_loop_prop('columns', 4);
    wc_set_loop_prop('current_page', $paged);
    wc_set_loop_prop('per_page', $per_page);
    wc_set_loop_prop('total', (int) $products->found_posts);
    wc_set_loop_prop('total_pages', (int) $products->max_num_pages);
    wc_set_loop_prop('is_paginated', $products->max_num_pages > 1);

    ob_start();

    woocommerce_product_loop_start();

    while ($products->have_posts()) {
        $products->the_post();
        wc_get_template_part('content', 'product');
    }

    woocommerce_product_loop_end();

    if ($products->max_num_pages > 1) {
        woocommerce_pagination();
    }

    $markup = ob_get_clean();

    wp_reset_postdata();
    $GLOBALS['wp_query'] = $previous_query;

    return $markup;
}

/**
 * Replace Elementor's empty archive widget output on the shop page with a manual Woo loop.
 *
 * @param string $content Rendered widget HTML.
 * @param ElementorWidget_Base $widget Widget instance.
 * @return string
 */
function papelito_shop_archive_widget_fallback($content, $widget)
{
    if (! function_exists('is_shop') || ! is_shop()) {
        return $content;
    }

    if (! $widget || 'wc-archive-products' !== $widget->get_name()) {
        return $content;
    }

    if (preg_match('/<ul class="products[^>]*>\s*<li/s', $content)) {
        return $content;
    }

    $fallback_markup = papelito_render_shop_products_loop_fallback();

    if ('' === $fallback_markup) {
        return $content;
    }

    $replaced = preg_replace('/<ul class="products[^>]*>\s*<\/ul>/s', $fallback_markup, $content, 1);

    return is_string($replaced) && '' !== $replaced ? $replaced : $content . $fallback_markup;
}
add_filter('elementor/widget/render_content', 'papelito_shop_archive_widget_fallback', 10, 2);


function my_related_products_query_args($query_args, $product_id)
{
    // Get the author ID of the current product
    $author_id = absint(get_post_field('post_author', $product_id));

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

            foreach ($admins as $admin) {
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
            if (! class_exists('\Dokan_SPMV_Product_Duplicator')) {
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
