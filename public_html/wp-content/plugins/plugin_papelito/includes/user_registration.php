<?php

define(
    'brazilian_states',
    array(
        '' => 'Selecione o estado da sua empresa',
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => 'Distrito Federal',
        'ES' => 'Espírito Santo',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PN' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
    )
);

function my_custom_woocommerce_register_fields_start()
{
    remove_action('woocommerce_register_form', 'dokan_seller_reg_form_fields');

    woocommerce_form_field(
        'store_name',
        array(
            'type' => 'text',
            'label' => 'Nome da loja',
            'required' => true,
            'placeholder' => 'Digite o nome de seu estabelecimento comercial',
            'class' => array('form-row-wide'),
        ),
        isset($_POST['store_name']) ? $_POST['store_name'] : ''
    );

    woocommerce_form_field(
        'account_first_name',
        array(
            'type' => 'text',
            'label' => 'Nome',
            'required' => true,
            'autocomplete' => 'given-name',
            'placeholder' => 'Digite seu nome',
            'class' => array('form-row-wide'),
        ),
        isset($_POST['account_first_name']) ? $_POST['account_first_name'] : ''
    );

    woocommerce_form_field(
        'account_last_name',
        array(
            'type' => 'text',
            'label' => 'Sobrenome',
            'required' => true,
            'autocomplete' => 'family-name',
            'placeholder' => 'Digite seu sobrenome',
            'class' => array('form-row-wide'),
        ),
        isset($_POST['account_last_name']) ? $_POST['account_last_name'] : ''
    );
}
add_action('woocommerce_register_form_start', 'my_custom_woocommerce_register_fields_start');

function my_custom_woocommerce_register_fields()
{
    if (is_edit_account_page()) {
        woocommerce_form_field(
            'store_name',
            array(
                'type' => 'text',
                'label' => 'Nome da loja',
                'required' => true,
                'placeholder' => 'Digite o nome de seu estabelecimento comercial',
                'class' => array('form-row-wide'),
            ),
            get_meta_or_post_data('store_name')
        );
    }

    woocommerce_form_field(
        'phone_number',
        array(
            'type' => 'tel',
            'label' => 'Telefone',
            'required' => true,
            'autocomplete' => 'tel',
            'placeholder' => 'Digite seu número de telefone',
            'class' => array('form-row-wide'),
        ),
        get_meta_or_post_data('phone_number')
    );

    woocommerce_form_field(
        'cnpj',
        array(
            'type' => 'text',
            'label' => 'CNPJ',
            'required' => true,
            'placeholder' => 'Digite seu CNPJ',
            'class' => array('form-row-wide'),
        ),
        get_meta_or_post_data('cnpj')
    );

    woocommerce_form_field(
        'instagram',
        array(
            'type' => 'text',
            'label' => 'Instagram',
            'placeholder' => 'Digite o endereço do instagram do seu negócio',
            'class' => array('form-row-wide'),
        ),
        get_meta_or_post_data('instagram')
    );

    woocommerce_form_field(
        'state',
        array(
            'type' => 'select',
            'label' => 'Estado',
            'required' => true,
            'placeholder' => 'Selecione o estado da sua empresa',
            'class' => array('form-row-wide'),
            'options' => brazilian_states
        ),
        get_meta_or_post_data('state')
    );

    woocommerce_form_field(
        'city',
        array(
            'type' => 'text',
            'label' => 'Cidade',
            'required' => true,
            'placeholder' => 'Digite a cidade da sua empresa',
            'class' => array('form-row-wide'),
        ),
        get_meta_or_post_data('city')
    );

    woocommerce_form_field(
        'cep',
        array(
            'type' => 'text',
            'label' => 'CEP',
            'required' => true,
            'placeholder' => 'Digite o CEP da sua empresa',
            'class' => array('form-row-wide'),
        ),
        get_meta_or_post_data('cep')
    );
}
add_action('woocommerce_edit_account_form', 'my_custom_woocommerce_register_fields');
add_action('woocommerce_register_form', 'my_custom_woocommerce_register_fields');

function get_meta_or_post_data($key)
{
    if (isset($_POST[$key])) {
        return $_POST[$key];
    } else {
        return get_user_meta(get_current_user_id(), $key, true);
    }
}

function enqueue_user_register_scripts()
{
    if (is_account_page() || is_wc_endpoint_url('edit-account')) {
        wp_enqueue_script('JQuery Mask', 'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js');
        wp_enqueue_script('Vendor Registration Fields', plugin_dir_url(__FILE__) . '../js/user_registration.js', array('JQuery Mask'));
        wp_enqueue_style('Vendor Registration Fields Styles', plugin_dir_url(__FILE__) . '../css/user_registration.css');
    }
}
add_action('wp_enqueue_scripts', 'enqueue_user_register_scripts');

// Validate the phone number field.
function my_custom_validate_register_custom_fields($errors)
{
    if (is_array($errors)) {
        $errors = $errors[0];
    }

    if (!isset($_POST['store_name']) || empty($_POST['store_name'])) {
        $errors->add('store_name_error', 'Informe o nome da sua loja, por favor.');
    }

    if (!isset($_POST['account_first_name']) || empty($_POST['account_first_name'])) {
        $errors->add('account_first_name_error', 'Informe o seu nome, por favor.');
    }

    if (!isset($_POST['account_last_name']) || empty($_POST['account_last_name'])) {
        $errors->add('account_last_name_error', 'Informe o seu sobrenome, por favor.');
    }

    if (!isset($_POST['phone_number']) || !preg_match('/^\(\d{2}\) 9?\d{4}\-?\d{4}$/', $_POST['phone_number'])) {
        $errors->add('phone_number_error', 'Informe um número de telefone válido, por favor.');
    }

    if (!isset($_POST['cnpj']) || !preg_match('/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', $_POST['cnpj'])) {
        $errors->add('cnpj_error', 'Informe um CNPJ válido, por favor.');
    }

    if (!isset($_POST['state']) || !array_key_exists($_POST['state'], brazilian_states) || empty($_POST['state'])) {
        $errors->add('state_error', 'Informe o estado, por favor.');
    }

    if (!isset($_POST['city']) || empty($_POST['city'])) {
        $errors->add('city_error', 'Informe a cidade, por favor.');
    }

    if (!isset($_POST['cep']) || !preg_match('/^\d{2}\.\d{3}-\d{3}$/', $_POST['cep'])) {
        $errors->add('cep_error', 'Informe o CEP válido, por favor.');
    }

    return $errors;
}
add_filter('woocommerce_registration_errors', 'my_custom_validate_register_custom_fields');
add_action('woocommerce_save_account_details_errors', 'my_custom_validate_register_custom_fields');

// Save the phone number field.
function my_custom_save_register_custom_fields($customer_id, $new_customer_data)
{
    $user_data = array();
    if (!is_edit_account_page()) {
        if (isset($_POST['account_first_name']) && !empty($_POST['account_first_name'])) {
            $user_data['ID'] = $customer_id;
            $user_data['first_name'] = $_POST['account_first_name'];
        }

        if (isset($_POST['account_last_name']) && !empty($_POST['account_last_name'])) {
            $user_data['ID'] = $customer_id;
            $user_data['last_name'] = $_POST['account_last_name'];
        }
    }

    if (isset($_POST['store_name']) && !empty($_POST['store_name'])) {
        update_user_meta($customer_id, 'store_name', sanitize_text_field($_POST['store_name']));
    }

    if (isset($_POST['phone_number']) && !empty($_POST['phone_number'])) {
        update_user_meta($customer_id, 'phone_number', sanitize_text_field($_POST['phone_number']));
    }

    if (isset($_POST['cnpj']) && !empty($_POST['cnpj'])) {
        update_user_meta($customer_id, 'cnpj', sanitize_text_field($_POST['cnpj']));
    }

    if (isset($_POST['instagram'])) {
        update_user_meta($customer_id, 'instagram', sanitize_text_field($_POST['instagram']));
    }

    if (isset($_POST['state']) && !empty($_POST['state'])) {
        update_user_meta($customer_id, 'state', sanitize_text_field($_POST['state']));
    }

    if (isset($_POST['city']) && !empty($_POST['city'])) {
        update_user_meta($customer_id, 'city', sanitize_text_field($_POST['city']));
    }

    if (isset($_POST['cep']) && !empty($_POST['cep'])) {
        update_user_meta($customer_id, 'cep', sanitize_text_field($_POST['cep']));
    }

    if (!empty($user_data)) {
        wp_update_user($user_data);
    }
}
add_action('woocommerce_created_customer', 'my_custom_save_register_custom_fields', 10, 2);
?>
