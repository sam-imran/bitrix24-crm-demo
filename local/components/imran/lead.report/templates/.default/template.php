<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

ob_start();

$APPLICATION->IncludeComponent(
    'bitrix:main.ui.filter',
    '',
    [
        'FILTER_ID'             => $arResult['FILTER_ID'],
        'FILTER'                => $arResult['FILTER_FIELDS'],
        'ENABLE_LABEL'          => true,
        'ENABLE_LIVE_SEARCH'    => false,
        'DISABLE_SEARCH'        => true,
        'RESET_TO_DEFAULT_MODE' => true,
        'FILTER_PRESETS'        => $arResult['FILTER_PRESETS'],
    ]
);

$html = ob_get_clean();
$APPLICATION->AddViewContent('below_pagetitle', $html);
?>

<div id='result'></div>