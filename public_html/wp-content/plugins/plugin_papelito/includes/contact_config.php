<?php
const PAPELITO_CONTACT_API_NAMESPACE = 'papelito/v1';

function papelito_contact_phone_default(): string { return '+556198364920'; }
function papelito_contact_phone(): string {
    $value = get_option( 'papelito_contact_phone', papelito_contact_phone_default() );
    return is_string( $value ) && preg_match( '/^\+?[0-9 ()-]{10,20}$/', $value ) ? $value : papelito_contact_phone_default();
}
function papelito_contact_require_admin(): bool { return current_user_can( 'manage_options' ); }
add_action( 'rest_api_init', static function (): void {
    register_rest_route( PAPELITO_CONTACT_API_NAMESPACE, '/home/contact-config', array(
        'methods' => WP_REST_Server::READABLE, 'permission_callback' => '__return_true',
        'callback' => static fn() => array( 'phone' => papelito_contact_phone() ),
    ) );
    register_rest_route( PAPELITO_CONTACT_API_NAMESPACE, '/admin/contact-config', array(
        'methods' => WP_REST_Server::READABLE, 'permission_callback' => 'papelito_contact_require_admin',
        'callback' => static fn() => array( 'phone' => papelito_contact_phone() ),
    ) );
    register_rest_route( PAPELITO_CONTACT_API_NAMESPACE, '/admin/contact-config', array(
        'methods' => WP_REST_Server::EDITABLE, 'permission_callback' => 'papelito_contact_require_admin',
        'callback' => static function ( WP_REST_Request $request ) {
            $phone = sanitize_text_field( (string) $request->get_param( 'phone' ) );
            if ( ! preg_match( '/^\+?[0-9 ()-]{10,20}$/', $phone ) ) {
                return new WP_Error( 'papelito_invalid_contact_phone', 'Informe um telefone válido.', array( 'status' => 422 ) );
            }
            update_option( 'papelito_contact_phone', $phone, false );
            return array( 'phone' => papelito_contact_phone() );
        },
    ) );
} );
