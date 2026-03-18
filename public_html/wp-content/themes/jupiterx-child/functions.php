<?php

defined( 'ABSPATH' ) || exit;

// Include Jupiter X.
require_once( get_template_directory() . '/lib/init.php' );

/**
 * Enqueue assets.
 *
 * Add custom style and script.
 */
jupiterx_add_smart_action( 'wp_enqueue_scripts', 'jupiterx_child_enqueue_scripts', 8 );

function jupiterx_child_enqueue_scripts() {

	// Add custom script.
	wp_enqueue_style(
		'jupiterx-child',
		get_stylesheet_directory_uri() . '/assets/css/style.css'
	);

	// Add custom script.
	wp_enqueue_script(
		'jupiterx-child',
		get_stylesheet_directory_uri() . '/assets/js/script.js',
		[ 'jquery' ],
		false,
		true
	);
}

// Escolher sidebar por post
function sidebar_select_metabox() {
    add_meta_box(
        'sidebar_select',
        __( 'Select Sidebar', 'textdomain' ),
        'sidebar_select_metabox_callback',
        'post'
    );
}
add_action( 'add_meta_boxes', 'sidebar_select_metabox' );

function sidebar_select_metabox_callback( $post ) {
    $selected_sidebar = get_post_meta( $post->ID, 'selected_sidebar', true );
    wp_nonce_field( 'jupiterx_child_sidebar_select', 'jupiterx_child_sidebar_select_nonce' );

    echo '<select id="sidebar_select" name="sidebar_select">';
    foreach ( $GLOBALS['wp_registered_sidebars'] as $sidebar ) {
        $selected = selected( $selected_sidebar, $sidebar['id'], false );
        echo '<option value="' . esc_attr( $sidebar['id'] ) . '" ' . $selected . '>' . esc_html( $sidebar['name'] ) . '</option>';
    }
    echo '</select>';
}

function save_sidebar_select( $post_id ) {
    if ( ! isset( $_POST['jupiterx_child_sidebar_select_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jupiterx_child_sidebar_select_nonce'] ) ), 'jupiterx_child_sidebar_select' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['sidebar_select'] ) ) {
        return;
    }

    $sidebar_id = sanitize_key( wp_unslash( $_POST['sidebar_select'] ) );

    if ( ! isset( $GLOBALS['wp_registered_sidebars'][ $sidebar_id ] ) ) {
        delete_post_meta( $post_id, 'selected_sidebar' );

        return;
    }

    update_post_meta( $post_id, 'selected_sidebar', $sidebar_id );
}
add_action( 'save_post', 'save_sidebar_select' );

function display_selected_sidebar( $atts ) {
    global $post;
    $selected_sidebar = get_post_meta( $post->ID, 'selected_sidebar', true );
    if ( !function_exists('dynamic_sidebar') || !dynamic_sidebar($selected_sidebar) ) :
    endif;
}
add_shortcode( 'selected_sidebar', 'display_selected_sidebar' );

// fim do código de escolher a sidebar


/**
 * Example 1
 *
 * Modify markups and attributes.
 */
// jupiterx_add_smart_action( 'wp', 'jupiterx_setup_document' );

function jupiterx_setup_document() {

	// Header
	jupiterx_add_attribute( 'jupiterx_header', 'class', 'jupiterx-child-header' );

	// Breadcrumb
	jupiterx_remove_action( 'jupiterx_breadcrumb' );

	// Post image
	jupiterx_modify_action_hook( 'jupiterx_post_image', 'jupiterx_post_header_before_markup' );

	// Post read more
	jupiterx_replace_attribute( 'jupiterx_post_more_link', 'class' , 'btn-outline-secondary', 'btn-danger' );

	// Post related
	jupiterx_modify_action_priority( 'jupiterx_post_related', 11 );

}

/**
 * Example 2
 *
 * Modify the sub footer credit text.
 */
// jupiterx_add_smart_action( 'jupiterx_subfooter_credit_text_output', 'jupiterx_child_modify_subfooter_credit' );

function jupiterx_child_modify_subfooter_credit() { ?>

	<a href="https//jupiterx.com" target="_blank">Jupiter X Child</a> theme for <a href="http://wordpress.org" target="_blank">WordPress</a>	

<?php }

function my_wc_free_shipping_by_shipping_class( $rates, $package ) {
	$shipping_class = 'entrega-gratuita'; // Slug da sua classe de entrega.
	$allow_free_shipping = true;
	// Verifica se todos os produtos precisam ser entregues e se possuem a class de entrega selecionada.
	foreach ( $package['contents'] as $value ) {
		$product = $value['data'];
		if ( $product->needs_shipping() && $shipping_class !== $product->get_shipping_class() ) {
			$allow_free_shipping = false;
			break;
		}
	}
	// Remove a entrega gratuita se algum produto não possuir a classe de entrega selecionada.
	if ( ! $allow_free_shipping ) {
		foreach ( $rates as $rate_id => $rate ) {
			if ( 'free_shipping' === $rate->method_id ) {
				unset( $rates[ $rate_id ] );
				break;
			}
		}
	}
	return $rates;
}
add_filter( 'woocommerce_package_rates', 'my_wc_free_shipping_by_shipping_class', 100, 2 );
