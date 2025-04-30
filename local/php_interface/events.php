<?php

include($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/classes/imran/handlers/LeadContactBinder.php');
include($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/classes/imran/handlers/LeadResponsibleUpdater.php');
include($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/classes/imran/handlers/LeadTabBuilder.php');
include($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/classes/imran/handlers/UserPasswordHandler.php');

use Bitrix\Main\EventManager;

$eventManager = EventManager::getInstance();

/* Автоматизация процесса привязки Контакта при создании Лида + сортировка Лидов, созданных внутренними сотрудниками */
$eventManager->addEventHandler(
    'crm',
    'OnAfterCrmLeadAdd',
    ['Imran\Handlers\LeadContactBinder', 'onLeadAdd']
);

/* Функционал автоматического назначения ответственного в Лиде при первой активности */
$eventManager->addEventHandler(
    'crm',
    'OnActivityAdd',
    ['Imran\Handlers\LeadResponsibleUpdater', 'onActivityAdd']
);

$eventManager->addEventHandler(
    'tasks',
    'OnTaskAdd',
    ['Imran\Handlers\LeadResponsibleUpdater', 'onTaskAdd']
);

$eventManager->addEventHandler(
    'voximplant',
    'onCallEnd',
    ['Imran\Handlers\LeadResponsibleUpdater', 'onCallEnd']
);

/* Вывод вкладки с историей взаимодействий в карточке сделки */
$eventManager->addEventHandler(
    'crm',
    'onEntityDetailsTabsInitialized',
    ['Imran\Handlers\LeadTabBuilder', 'onLeadShow']
);

/* Инициация смены пароля при добавлении нового пользователя */
$eventManager->addEventHandler(
    'main',
    'OnAfterUserAdd',
    ['Imran\Handlers\UserPasswordHandler', 'onAfterUserAdd']
);
