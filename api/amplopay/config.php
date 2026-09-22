<?php
/*
 * AMPLOPAY — credencial configurada.
 * Este arquivo não deve ser exposto nem incluído em HTML/JavaScript.
 */
define('AMPLOPAY_PUBLIC_KEY', 'COLOQUE_AQUI_A_CHAVE_PUBLICA_AUTORIZADA');
define('AMPLOPAY_SECRET_KEY', 'COLOQUE_AQUI_A_CHAVE_PRIVADA_AUTORIZADA');

define('AMPLOPAY_PIX_ENDPOINT', 'https://app.amplopay.com/api/v1/gateway/pix/receive');

define('VALORES_PERMITIDOS', [
    30.00,
    50.00,
    75.00,
    100.00,
    150.00,
    200.00,
    300.00,
    500.00,
    1000.00
]);
