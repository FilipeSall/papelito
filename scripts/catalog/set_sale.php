<?php
// Sets sale prices on 4 home flash-sale products
$sales = [
    11798 => 199.90, // Seda Alfafa King Size       (R$ 240 → R$ 199,90)
    11784 => 74.90,  // Seda Hemp King Size         (R$ 90  → R$ 74,90)
    11776 => 99.90,  // Seda Slim King Size         (R$ 121 → R$ 99,90)
    11794 => 184.90, // Seda Insane Brown King Size (R$ 223 → R$ 184,90)
];
foreach ($sales as $id => $sale) {
    $p = wc_get_product($id);
    if (!$p) { WP_CLI::warning("missing product #$id"); continue; }
    $p->set_sale_price(number_format($sale, 2, '.', ''));
    $p->set_price(number_format($sale, 2, '.', ''));
    $p->save();
    WP_CLI::log("#$id {$p->get_name()} regular=R\$ {$p->get_regular_price()}  sale=R\$ {$p->get_sale_price()}");
}
