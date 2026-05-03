#!/usr/bin/env php
<?php
/**
 * Papelito catalog import.
 * Run: wp eval-file /tmp/import_catalog.php
 */

if ( ! defined( 'WP_CLI' ) ) {
    fwrite( STDERR, "Run via wp-cli.\n" );
    exit( 1 );
}

$AUTHOR_LOGIN = getenv( 'PAPELITO_AUTHOR_LOGIN' ) ?: 'marketing';
$JSON_PATH    = getenv( 'PAPELITO_CATALOG_JSON' ) ?: '/tmp/catalog.json';

$author = get_user_by( 'login', $AUTHOR_LOGIN );
if ( ! $author ) {
    WP_CLI::error( "User '$AUTHOR_LOGIN' not found." );
}
$AUTHOR_ID = (int) $author->ID;
WP_CLI::log( "Author: $AUTHOR_LOGIN (#$AUTHOR_ID)" );

if ( ! function_exists( 'wc_get_product' ) ) {
    WP_CLI::error( 'WooCommerce not active.' );
}

$catalog = json_decode( file_get_contents( $JSON_PATH ), true );
if ( ! is_array( $catalog ) ) {
    WP_CLI::error( "Cannot read $JSON_PATH" );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

/* ------------- Categories ------------- */
function ensure_term( $name, $taxonomy, $parent = 0, $slug = null ) {
    $existing = term_exists( $name, $taxonomy, $parent ?: null );
    if ( $existing ) {
        return is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
    }
    $args = array( 'parent' => $parent );
    if ( $slug ) $args['slug'] = $slug;
    $r = wp_insert_term( $name, $taxonomy, $args );
    if ( is_wp_error( $r ) ) {
        WP_CLI::warning( "term '$name': " . $r->get_error_message() );
        return 0;
    }
    return (int) $r['term_id'];
}

$cat_ids = array();
$slug_map = array(
    'Sedas'      => 'sedas',
    'Piteiras'   => 'piteiras',
    'Filtros'    => 'filtros',
    'Acessórios' => 'acessorios',
);
foreach ( $slug_map as $name => $slug ) {
    $cat_ids[ $name ] = ensure_term( $name, 'product_cat', 0, $slug );
}

/* Subcategories collected from catalog data */
$subcat_ids = array();
$collect_sub = function ( $cat, $sub ) use ( &$subcat_ids, $cat_ids ) {
    if ( ! $sub || $sub === '-' ) return null;
    $key = "$cat::$sub";
    if ( isset( $subcat_ids[ $key ] ) ) return $subcat_ids[ $key ];
    $parent = $cat_ids[ $cat ] ?? 0;
    $tid    = ensure_term( $sub, 'product_cat', $parent );
    $subcat_ids[ $key ] = $tid;
    return $tid;
};

/* ------------- 'Cor' attribute taxonomy ------------- */
global $wpdb;
$attr_slug = 'cor';
$existing  = $wpdb->get_var( $wpdb->prepare(
    "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name=%s",
    $attr_slug
) );
if ( ! $existing ) {
    $wpdb->insert( "{$wpdb->prefix}woocommerce_attribute_taxonomies", array(
        'attribute_label'   => 'Cor',
        'attribute_name'    => $attr_slug,
        'attribute_type'    => 'select',
        'attribute_orderby' => 'menu_order',
        'attribute_public'  => 1,
    ) );
    $attr_id = (int) $wpdb->insert_id;
    delete_transient( 'wc_attribute_taxonomies' );
    // Register taxonomy now so we can insert terms.
    register_taxonomy( 'pa_' . $attr_slug, array( 'product', 'product_variation' ), array(
        'hierarchical' => false,
        'public'       => false,
        'rewrite'      => false,
    ) );
    WP_CLI::log( "Created attribute 'Cor' (#$attr_id)" );
} else {
    if ( ! taxonomy_exists( 'pa_' . $attr_slug ) ) {
        register_taxonomy( 'pa_' . $attr_slug, array( 'product', 'product_variation' ), array(
            'hierarchical' => false, 'public' => false, 'rewrite' => false,
        ) );
    }
    WP_CLI::log( "Attribute 'Cor' already exists (#$existing)" );
}

/* ------------- Helper: upload image ------------- */
function papelito_attach_image( $product_id, $image_path ) {
    if ( ! $image_path || ! file_exists( $image_path ) ) return 0;
    $existing = get_posts( array(
        'post_type'      => 'attachment',
        'posts_per_page' => 1,
        'meta_key'       => '_papelito_source',
        'meta_value'     => $image_path,
    ) );
    if ( $existing ) {
        $att_id = (int) $existing[0]->ID;
    } else {
        $tmp = wp_tempnam( basename( $image_path ) );
        copy( $image_path, $tmp );
        $file_array = array(
            'name'     => basename( $image_path ),
            'tmp_name' => $tmp,
        );
        $att_id = media_handle_sideload( $file_array, $product_id );
        if ( is_wp_error( $att_id ) ) {
            @unlink( $tmp );
            WP_CLI::warning( "image '$image_path': " . $att_id->get_error_message() );
            return 0;
        }
        update_post_meta( $att_id, '_papelito_source', $image_path );
    }
    set_post_thumbnail( $product_id, $att_id );
    return $att_id;
}

/* ------------- Helper: build slug ------------- */
function papelito_unique_slug( $base ) {
    $slug = sanitize_title( $base );
    $i = 1;
    $orig = $slug;
    while ( get_page_by_path( $slug, OBJECT, 'product' ) ) {
        $slug = $orig . '-' . ( ++$i );
    }
    return $slug;
}

/* ------------- Save simple/draft product ------------- */
function papelito_save_product( $p, $author_id, $cat_ids, $collect_sub, $status_override = null ) {
    $product = new WC_Product_Simple();
    $product->set_name( $p['name'] );
    $product->set_status( $status_override ?: ( $p['status'] ?: 'publish' ) );
    $product->set_catalog_visibility( 'visible' );
    $product->set_description( $p['description'] ?: '' );
    $product->set_short_description( '' );
    $product->set_slug( sanitize_title( $p['slug'] ?: $p['name'] ) );
    if ( ! empty( $p['sku'] ) ) {
        $product->set_sku( $p['sku'] );
    }
    if ( ! is_null( $p['price'] ) ) {
        $price_str = number_format( $p['price'], 2, '.', '' );
        $product->set_regular_price( $price_str );
        $product->set_price( $price_str );
    }
    if ( ! empty( $p['peso_liq_kg'] ) ) $product->set_weight( $p['peso_liq_kg'] );
    if ( ! empty( $p['altura_cm'] ) )    $product->set_height( $p['altura_cm'] );
    if ( ! empty( $p['largura_cm'] ) )   $product->set_width( $p['largura_cm'] );
    if ( ! empty( $p['comprimento_cm'] ) ) $product->set_length( $p['comprimento_cm'] );

    $product->set_manage_stock( false );
    $product->set_stock_status( 'instock' );

    // Categories
    $cats = array();
    if ( ! empty( $cat_ids[ $p['category'] ] ) ) {
        $cats[] = (int) $cat_ids[ $p['category'] ];
    }
    if ( ! empty( $p['subcategory'] ) ) {
        $sid = $collect_sub( $p['category'], $p['subcategory'] );
        if ( $sid ) $cats[] = (int) $sid;
    }
    $product->set_category_ids( $cats );

    $id = $product->save();

    // author
    wp_update_post( array( 'ID' => $id, 'post_author' => $author_id ) );

    // peso bruto and TODO meta
    if ( ! empty( $p['peso_bruto_kg'] ) ) update_post_meta( $id, '_papelito_peso_bruto_kg', $p['peso_bruto_kg'] );
    if ( ! empty( $p['todo'] ) )           update_post_meta( $id, '_papelito_import_todo', wp_json_encode( $p['todo'] ) );

    // image
    if ( ! empty( $p['image_path'] ) ) papelito_attach_image( $id, $p['image_path'] );

    return $id;
}

/* ------------- Run imports ------------- */
$report = array( 'simple' => 0, 'variable' => 0, 'draft' => 0, 'image_attached' => 0, 'errors' => array() );

foreach ( $catalog['simple'] as $p ) {
    try {
        $id = papelito_save_product( $p, $AUTHOR_ID, $cat_ids, $collect_sub );
        $report['simple']++;
        if ( ! empty( $p['image_path'] ) ) $report['image_attached']++;
        WP_CLI::log( sprintf( "[simple] #%d %s", $id, $p['name'] ) );
    } catch ( Exception $e ) {
        $report['errors'][] = $p['name'] . ': ' . $e->getMessage();
    }
}

foreach ( $catalog['draft'] as $p ) {
    try {
        $p['status'] = 'draft';
        $id = papelito_save_product( $p, $AUTHOR_ID, $cat_ids, $collect_sub, 'draft' );
        $report['draft']++;
        WP_CLI::log( sprintf( "[draft]  #%d %s — TODO: %s", $id, $p['name'], implode('; ', $p['todo'] ) ) );
    } catch ( Exception $e ) {
        $report['errors'][] = $p['name'] . ': ' . $e->getMessage();
    }
}

/* ------------- Variable products ------------- */
foreach ( $catalog['variable'] as $g ) {
    try {
        $product = new WC_Product_Variable();
        $product->set_name( $g['name'] );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_description( $g['description'] ?: '' );
        $product->set_slug( sanitize_title( $g['name'] ) );
        $product->set_manage_stock( false );
        $product->set_stock_status( 'instock' );

        // categories
        $cats = array();
        if ( ! empty( $cat_ids[ $g['category'] ] ) ) $cats[] = (int) $cat_ids[ $g['category'] ];
        $sid = $collect_sub( $g['category'], $g['subcategory'] ?? null );
        if ( $sid ) $cats[] = (int) $sid;
        $product->set_category_ids( $cats );

        // attribute "Cor"
        $colors = array();
        foreach ( $g['variants'] as $v ) $colors[] = $v['color'];
        $colors = array_values( array_unique( $colors ) );
        $term_ids = array();
        foreach ( $colors as $c ) {
            $term = term_exists( $c, 'pa_cor' );
            if ( ! $term ) $term = wp_insert_term( $c, 'pa_cor' );
            if ( ! is_wp_error( $term ) ) {
                $term_ids[ $c ] = (int) $term['term_id'];
            }
        }
        $attr = new WC_Product_Attribute();
        $attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_cor' ) );
        $attr->set_name( 'pa_cor' );
        $attr->set_options( array_values( $term_ids ) );
        $attr->set_visible( true );
        $attr->set_variation( true );
        $product->set_attributes( array( $attr ) );

        $product_id = $product->save();
        wp_update_post( array( 'ID' => $product_id, 'post_author' => $AUTHOR_ID ) );

        // attach color terms
        wp_set_object_terms( $product_id, array_values( $term_ids ), 'pa_cor' );

        // create variations
        foreach ( $g['variants'] as $v ) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id( $product_id );
            $variation->set_status( 'publish' );
            $variation->set_attributes( array( 'pa_cor' => sanitize_title( $v['color'] ) ) );
            if ( ! empty( $v['sku'] ) )   $variation->set_sku( $v['sku'] );
            if ( ! is_null( $v['price'] ) ) {
                $variation->set_regular_price( number_format( $v['price'], 2, '.', '' ) );
                $variation->set_price( number_format( $v['price'], 2, '.', '' ) );
            }
            $variation->set_manage_stock( false );
            $variation->set_stock_status( 'instock' );
            $variation->save();
        }
        WC_Product_Variable::sync( $product_id );

        if ( ! empty( $g['todo'] ) ) update_post_meta( $product_id, '_papelito_import_todo', wp_json_encode( $g['todo'] ) );

        $report['variable']++;
        WP_CLI::log( sprintf( "[variable] #%d %s (%d variants)", $product_id, $g['name'], count( $g['variants'] ) ) );
    } catch ( Exception $e ) {
        $report['errors'][] = $g['name'] . ': ' . $e->getMessage();
    }
}

WP_CLI::log( "\n=== REPORT ===" );
WP_CLI::log( "simple:   " . $report['simple'] );
WP_CLI::log( "draft:    " . $report['draft'] );
WP_CLI::log( "variable: " . $report['variable'] );
WP_CLI::log( "images attached: " . $report['image_attached'] );
if ( $report['errors'] ) {
    WP_CLI::log( "ERRORS:" );
    foreach ( $report['errors'] as $e ) WP_CLI::log( "  - $e" );
}
