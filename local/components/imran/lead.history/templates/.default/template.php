<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/** @var array $arResult */
?>
<link rel="stylesheet" type="text/css" href="<?= $this->GetFolder() ?>/style.css">
<script type="text/javascript" src="<?= $this->GetFolder() ?>/script.js"></script>

<div class="crm-grid-container">
    <?php
    $APPLICATION->IncludeComponent(
        'bitrix:main.ui.grid',
        '',
        [
            'GRID_ID' => 'LEAD_HISTORY',
            'COLUMNS' => $arResult['COLUMNS'],
            'ROWS' => $arResult['ROWS'],
            'AJAX_MODE' => 'Y',
            'AJAX_OPTION_JUMP' => 'N',
            'AJAX_OPTION_HISTORY' => 'N',
            'SHOW_ROW_CHECKBOXES' => false,
            'SHOW_CHECK_ALL_CHECKBOXES' => false,
            'SHOW_GRID_SETTINGS_MENU' => false,
        ]
    );
    ?>
</div>
