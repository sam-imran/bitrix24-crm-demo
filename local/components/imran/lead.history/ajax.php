<?php

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

$APPLICATION->IncludeComponent(
    'imran:lead.history',
    '',
    ['CACHE_TIME' => 0]
);
