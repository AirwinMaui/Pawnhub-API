<?php
// paymongo_config.php

define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: '');
define('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: '');
define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');

define(
    'PAYMONGO_SUCCESS_URL',
    'https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/mobile/paymongo_success.php'
);

define(
    'PAYMONGO_CANCEL_URL',
    'https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/mobile/paymongo_cancel.php'
);