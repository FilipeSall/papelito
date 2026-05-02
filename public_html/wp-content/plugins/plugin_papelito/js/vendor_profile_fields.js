jQuery(function ($) {
    var cepsTable = document.getElementById('vendor_ceps');
    var addCepButton = document.getElementById('add-cep');
    var removeCepButtons = document.querySelectorAll('.remove-cep');
    $("[name='vendor_min_ceps[]'],[name='vendor_max_ceps[]']").mask("00.000-000");

    $("form").on("submit", function () {
        $("[name='vendor_min_ceps[]'],[name='vendor_max_ceps[]']").unmask();
    })

    $('#phone_number').mask('(00) 00000-0000');
    $('#cnpj').mask('00.000.000/0000-00');
    $('#cep').mask('00.000-000');
    $('#instagram').mask('@AA', {
        translation: {
            'A': { pattern: /[a-zA-Z0-9._]/, recursive: true }
        }
    });

    if (cepsTable) {
        addCepButton.addEventListener('click', function () {
            var newCepInputMin = document.createElement('input');
            newCepInputMin.type = 'text';
            newCepInputMin.name = "vendor_min_ceps[]";
            newCepInputMin.placeholder = "CEP mínimo";
            newCepInputMin.classList.add('regular-text');

            var newCepInputMax = document.createElement('input');
            newCepInputMax.type = 'text';
            newCepInputMax.name = "vendor_max_ceps[]";
            newCepInputMax.placeholder = "CEP máximo";
            newCepInputMax.classList.add('regular-text');

            var newRemoveCepButton = document.createElement('button');
            newRemoveCepButton.type = 'button';
            newRemoveCepButton.classList.add('button', 'remove-cep');
            newRemoveCepButton.textContent = 'Remover';
            newRemoveCepButton.addEventListener('click', removeCepRow);

            addCepButton.remove();
            let wrapperDiv = document.createElement("div");
            wrapperDiv.appendChild(newCepInputMin);
            wrapperDiv.appendChild(newCepInputMax);
            wrapperDiv.appendChild(newRemoveCepButton);
            wrapperDiv.appendChild(document.createElement('br'));

            cepsTable.appendChild(wrapperDiv);
            cepsTable.appendChild(addCepButton);

            $("[name='vendor_min_ceps[]'],[name='vendor_max_ceps[]']").mask("00.000-000");
        });

        removeCepButtons.forEach(function (button) {
            button.addEventListener('click', removeCepRow);
        });
    }

    function removeCepRow() {
        var wrapperDiv = this.parentElement;
        if (wrapperDiv && cepsTable.children.length > 2) {
            wrapperDiv.remove();
        }
    }
});