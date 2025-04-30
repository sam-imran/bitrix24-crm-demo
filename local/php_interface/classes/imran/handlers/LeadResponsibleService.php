<?php

namespace Imran\Handlers;

use Imran\Utils\TelegramDebugger as td;
use Bitrix\Main\Loader;
use Bitrix\Crm\LeadTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\ContactTable;
use Bitrix\Main\UserTable;
use CTaskItem;
use CUserFieldEnum;
use CIBlockElement;
use CIBlockSection;
use CIntranetUtils;

/**
 * Класс для обработки лидов после привязки контакта.
 *
 * @package IMRAN
 */
class LeadResponsibleService
{
    /**
     * Запускает процесс обработки лида после привязки контакта.
     *
     * @param int      $leadId ID Лида.
     * @param int|null $contactId ID Контакта.
     *
     * @return void
     */
    public static function processLead(int $leadId, ?int $contactId = null): void
    {
        Loader::includeModule('crm');
        Loader::includeModule('tasks');
        Loader::includeModule('intranet');

        // Получить список опций для реквизита “Тег лида”
        $tags = self::getUserFieldEnumValues('UF_CRM_1741691268');

        // Получить список опций для реквизита “Причина отмены”
        $reasons = self::getUserFieldIblockValues(55);

        $lead = LeadTable::getList([
            'filter' => ['=ID' => $leadId],
            'select' => ['ID', 'STATUS_ID', 'TITLE', 'EMAIL']
        ])->fetch();

        // Контакта нет
        if ($contactId === null) {
            self::processLeadWithoutContact($lead, $tags, $reasons);

            return;
        }

        self::processLeadWithContact($lead, $contactId, $tags, $reasons);
    }

    /**
     * Обрабатывает лид без привязанного контакта.
     *
     * @param array $lead    Массив полей лида.
     * @param array $tags    Массив тегов лида.
     * @param array $reasons Массив причин отмены.
     *
     * @return void
     */
    private static function processLeadWithoutContact(array $lead, array $tags, array $reasons): void
    {
        // Поиск лидов в группе стадий "В работе" с тем же e-mail

        $leads = LeadTable::getList([
            'filter' => [
                '=STATUS_SEMANTIC_ID' => 'P',
                '=EMAIL' => $lead['EMAIL'],
                '!ID' => $lead['ID'],
            ],
            'select' => ['ID', 'DATE_CREATE', 'ASSIGNED_BY_ID']
        ])->fetchAll();

        // Нет лидов
        if (empty($leads)) {
            // Устанавливаем тег "Новый"
            if ($tagId = array_search('Новый', $tags, true)) {
                LeadTable::update($lead['ID'], [
                    'MODIFY_BY_ID' => 29, // Магия чисел
                    'UF_CRM_1741691268' => $tagId, // Никакой магии
                ]);
            }
            return;
        }

        // Отправляем лид в дубль
        self::junkDoubleLead($lead, $tags, $reasons);

        usort($leads, fn($a, $b) => $a['DATE_CREATE']->getTimestamp() <=> $b['DATE_CREATE']->getTimestamp());
        $oldestLead = reset($leads);

        // Cоздадем задачу
        if ($oldestLead) {
            self::createTask($lead, $oldestLead);
        }
    }

    /**
     * Обрабатывает лид c привязанным контактом.
     *
     * @param array $lead       Массив полей лида.
     * @param int   $contactId  ID контакта.
     * @param array $tags       Массив тегов лида.
     * @param array $reasons    Массив причин отмены.
     *
     * @return void
     */
    private static function processLeadWithContact(array $lead, int $contactId, array $tags, array $reasons): void
    {
        // Поиск сделок в группе стадий "В работе" с тем же контактом
        $deals = DealTable::getList([
            'filter' => [
                '=STAGE_SEMANTIC_ID'    => 'P',
                '=CONTACT_ID'           => $contactId,
            ],
            'select' => ['ID', 'DATE_CREATE', 'ASSIGNED_BY_ID']
        ])->fetchAll();

        // Есть сделки
        if (!empty($deals)) {
            self::junkDoubleLead($lead, $tags, $reasons);

            usort($deals, fn($a, $b) => $a['DATE_CREATE']->getTimestamp() <=> $b['DATE_CREATE']->getTimestamp());
            $oldestDeal = reset($deals);

            if ($oldestDeal) {
                $assignedId = $oldestDeal['ASSIGNED_BY_ID'];
                $assignedUser = UserTable::getList([
                    'filter' => ['=ID' => $assignedId],
                    'select' => ['ID', 'ACTIVE']
                ])->fetch();

                // Если ответственный не активен, ищем руководителя
                if ($assignedUser && $assignedUser['ACTIVE'] !== 'Y') {
                    if ($newAssignedId = self::getUserManager($assignedId)) {
                        DealTable::update($oldestDeal['ID'], [
                            'MODIFY_BY_ID' => 29,
                            'ASSIGNED_BY_ID' => $newAssignedId,
                        ]);
                    }
                }

                self::createTask($lead, $oldestDeal, 'D', 'По Контакту поступил новый Лид');
            }

            return;
        }

        // Нет сделок
        $email = ContactTable::getList([
            'filter' => ['=ID' => $contactId],
            'select' => ['ID', 'EMAIL']
        ])->fetch()['EMAIL'];

        if ($email) {
            $leads = LeadTable::getList([
                'filter' => [
                    '=STATUS_SEMANTIC_ID' => 'P',
                    '=EMAIL' => $email,
                    '!ID' => $lead['ID'],
                ],
                'select' => ['ID', 'DATE_CREATE', 'ASSIGNED_BY_ID']
            ])->fetchAll();

            if (empty($leads)) {
                // Устанавливаем тег "Повторный Спящий"
                if ($tagId = array_search('Повторный Спящий', $tags, true)) {
                    LeadTable::update($lead['ID'], [
                        'MODIFY_BY_ID' => 29,
                        'UF_CRM_1741691268' => $tagId,
                    ]);
                }
                return;
            }

            // Отправляем лид в дубль
            self::junkDoubleLead($lead, $tags, $reasons);

            usort($leads, fn($a, $b) => $a['DATE_CREATE']->getTimestamp() <=> $b['DATE_CREATE']->getTimestamp());
            $oldestLead = reset($leads);

            if ($oldestLead) {
                $assignedId = $oldestLead['ASSIGNED_BY_ID'];
                $assignedUser = UserTable::getList([
                    'filter' => ['=ID' => $assignedId],
                    'select' => ['ID', 'ACTIVE']
                ])->fetch();

                // Если ответственный не активен, ищем руководителя
                if ($assignedUser && $assignedUser['ACTIVE'] !== 'Y') {
                    if ($newAssignedId = self::getUserManager($assignedId)) {
                        LeadTable::update($oldestLead['ID'], [
                            'MODIFY_BY_ID' => 29,
                            'ASSIGNED_BY_ID' => $newAssignedId,
                        ]);

                        $oldestLead['ASSIGNED_BY_ID'] = $newAssignedId;
                    }
                }

                self::createTask($lead, $oldestLead, 'L', 'По Лиду поступил новый Лид');
            }
        }
    }

    /**
     * Отправка лида в дубль
     *
     * @param array $lead    Массив полей лида.
     * @param array $tags    Массив тегов лида.
     * @param array $reasons Массив причин отмены.
     *
     * @return void
     */
    private static function junkDoubleLead(array $lead, array $tags, array $reasons): void
    {
        $tagId = array_search('Повторный В работе', $tags, true);
        $reasonId = array_search('уже есть сделка / контакт', $reasons, true);

        $fields = [
            'MODIFY_BY_ID'      =>  29,
            'UF_CRM_1741691268' => $tagId,
        ];

        if ($lead['STATUS_ID'] === 'INTEREST') {
            $fields += [
                'ASSIGNED_BY_ID'    => 29,
                'UF_CRM_1713505108' => $reasonId,
                'UF_CRM_1713504434' => 'Дубль',
                'STATUS_ID'         => 'JUNK',
                'STATUS_SEMANTIC_ID' => 'F',
            ];
        }

        LeadTable::update($lead['ID'], $fields);
    }

    /**
     * Создание задачи по лиду / сделке
     *
     * @param array     $lead           Массив полей нового лида.
     * @param array     $fields         Массив полей сущности, по которой ставим задачу
     * @param string    $entityType     Буквенный код сущности, по которой ставим задачу
     * @param string    $title          Название задачи
     *
     * @return void
     */
    private static function createTask(
        array $lead,
        array $fields,
        string $entityType = 'L',
        string $title = 'Поступил новый Лид'
    ): void {
        $newTask = [
            'TITLE' => $title,
            'DESCRIPTION' => "{$title}: {$lead['TITLE']}. \n" .
                "Ссылка на Лид: [URL=/crm/lead/details/{$lead['ID']}/]Открыть[/URL]",
            'RESPONSIBLE_ID' => $fields['ASSIGNED_BY_ID'],
            'CREATED_BY' => 29,
            'UF_CRM_TASK' => [$entityType . '_' . $fields['ID']]
        ];

        $taskItem = CTaskItem::add($newTask, 29);
    }

    /**
     * Получает ID руководителя пользователя.
     *
     * @param int $userId ID пользователя.
     *
     * @return int|null ID руководителя или null, если не найден.
     */
    private static function getUserManager(int $userId): ?int
    {
        $sections = CIntranetUtils::GetUserDepartments($userId);

        foreach ($sections as $section) {
            while ($section > 0) {
                $manager = CIntranetUtils::GetDepartmentManagerID($section);
                if (!empty($manager)) {
                    return (int) $manager;
                }

                $res = CIBlockSection::GetByID($section);
                if ($sectionInfo = $res->GetNext()) {
                    $section = (int) $sectionInfo['IBLOCK_SECTION_ID'];
                } else {
                    break;
                }
            }
        }

        return null;
    }


    /**
     * Получает значения для списочного пользовательского поля CRM.
     *
     * @param string $fieldId Код пользовательского поля (например, 'UF_CRM_1741691268').
     *
     * @return array Возвращает массив значений в формате [ID => VALUE].
     */
    private static function getUserFieldEnumValues(string $fieldId): array
    {
        $values = [];

        $res = CUserFieldEnum::GetList([], ['USER_FIELD_NAME' => $fieldId]);
        while ($enum = $res->Fetch()) {
            $values[$enum['ID']] = $enum['VALUE'];
        }

        return $values;
    }

    /**
     * Получает значения для пользовательского поля типа привязка к ИБ.
     *
     * @param int $iblockId ID инфоблока.
     *
     * @return array
     */
    private static function getUserFieldIblockValues(int $iblockId): array
    {
        Loader::includeModule('iblock');

        $result = [];

        $rs = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', 'NAME']
        );

        while ($el = $rs->Fetch()) {
            $result[$el['ID']] = $el['NAME'];
        }

        return $result;
    }
}
