jQuery(function ($) {
    let reg_email = document.getElementById("reg_email");
    if (reg_email) {
        reg_email.placeholder = "Digite seu email";
    }

    let account_email = document.getElementById("account_email");
    if (account_email) {
        account_email.placeholder = "Digite seu email";
    }

    let reg_password = document.getElementById("reg_password");
    if (reg_password) {
        reg_password.placeholder = "Digite uma senha única";
    }

    $('#phone_number').mask('(00) 00000-0000');
    $('#cnpj').mask('00.000.000/0000-00');
    $('#cep').mask('00.000-000');
    $('#instagram').mask('@AA', {
        translation: {
            'A': { pattern: /[a-zA-Z0-9._]/, recursive: true }
        }
    });
});