<?php

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

$APPLICATION->SetTitle("Выгрузка сквозного отчета по лидам");
?>
<?php
$APPLICATION->IncludeComponent(
    "imran:lead.report",
    "",
    []
);
?>
<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php");
