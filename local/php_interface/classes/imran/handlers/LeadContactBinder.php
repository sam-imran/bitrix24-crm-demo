<?php

namespace Imran\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Crm\ContactTable;
use Bitrix\Crm\FieldMultiTable;
use CCrmLead;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\PhoneNumber\Parser;
use Bitrix\Main\PhoneNumber\Format;
use Imran\Handlers\LeadResponsibleService;

/**
 * Класс LeadContactBinder
 *
 * Привязывает лид к существующему контакту
 * Для внутренних лидов - создаёт новый контакт, а также производит сортировку лида.
 */
class LeadContactBinder
{
     /**
     * Запускает привязку контакта к лиду вручную по ID лида.
     *
     * @param int $leadId ID лида.
     *
     * @return void
     */
    public static function bindContactToLead(int $leadId): void
    {
        if (!$leadId) {
            return;
        }

        $leadData = \CCrmLead::GetByID($leadId, false);

        if (!$leadData) {
            return;
        }

        $leadData['FM'] = self::getLeadMultiFields($leadId);
        self::processLead($leadData);
    }

    /**
     * Обработчик события OnAfterCrmLeadAdd.
     * Запускает обработку лида, если он в статусе "INTEREST".
     *
     * @param array $fields Данные лида.
     *
     * @return array|null Возвращает изменённые данные лида или null.
     */
    public static function onLeadAdd(array &$fields): ?array
    {
        if (!isset($fields['STATUS_ID']) || $fields['STATUS_ID'] !== 'INTEREST') {
            return null;
        }

        self::processLead($fields);
        return $fields;
    }

    /**
     * Обрабатывает лид: проверяет Email, ищет контакт, создаёт новый или привязывает существующий.
     *
     * @param array $fields Данные лида.
     *
     * @return void
     */
    private static function processLead(array $fields): void
    {
        Loader::includeModule('crm');
        if (empty($fields['FM']['EMAIL']) || !is_array($fields['FM']['EMAIL'])) {
            return;
        }

        $email = trim(reset($fields['FM']['EMAIL'])['VALUE']);
        if (!$email) {
            return;
        }

        $isInner = (strpos($email, '@ibs.ru') !== false);

        $contacts = self::findContactsByEmail($email);
        if (empty($contacts)) {
            if ($isInner) {
                // Добавляем контакт и фиксируем лид как успешный
                self::createContact($fields);

                return;
            }

            self::setLeadResponsible($fields['ID'], null);

            return;
        }

        if (count($contacts) === 1) {
            self::updateLeadContact($fields['ID'], $contacts[0]['ID']);
            if ($isInner) {
                // Сливаем лид в некачественные
                self::junkLead($fields);

                return;
            }

            self::setLeadResponsible($fields['ID'], $contacts[0]['ID']);

            return;
        }

        $leadPhones = self::extractLeadPhones($fields);
        $matchedContacts = self::filterContactsByPhone($contacts, $leadPhones);

        if (empty($matchedContacts)) {
            $matchedContacts = $contacts;
        }

        if (count($matchedContacts) === 1) {
            self::updateLeadContact($fields['ID'], $matchedContacts[0]['ID']);
            if ($isInner) {
                // Сливаем лид в некачественные
                self::junkLead($fields);

                return;
            }

            self::setLeadResponsible($fields['ID'], $matchedContacts[0]['ID']);

            return;
        }

        $leadName = trim($fields['NAME'] ?? '');

        if ($leadName !== '') {
            $matchedByName = array_filter($matchedContacts, function ($contact) use ($leadName) {
                return isset($contact['NAME']) && trim($contact['NAME']) === $leadName;
            });

            if (!empty($matchedByName)) {
                $matchedContacts = $matchedByName;
            }
        }

        self::updateLeadContact($fields['ID'], array_values($matchedContacts)[0]['ID']);
        if ($isInner) {
            // Сливаем лид в некачественные
            self::junkLead($fields);

            return;
        }

        self::setLeadResponsible($fields['ID'], array_values($matchedContacts)[0]['ID']);
    }

    /**
     * Получает множественные поля (FM) для лида.
     *
     * @param int $leadId ID лида.
     *
     * @return array Массив данных FM.
     */
    private static function getLeadMultiFields(int $leadId): array
    {
        $multiFields = [];

        $dbRes = \CCrmFieldMulti::GetList(
            [],
            ['ENTITY_ID' => 'LEAD', 'ELEMENT_ID' => $leadId]
        );

        while ($field = $dbRes->Fetch()) {
            $multiFields[$field['TYPE_ID']][] = [
                'VALUE' => $field['VALUE'],
                'VALUE_TYPE' => $field['VALUE_TYPE']
            ];
        }

        return $multiFields;
    }

    /**
     * Ищет контакты по Email.
     *
     * @param string $email Email для поиска.
     *
     * @return array Найденные контакты.
     */
    private static function findContactsByEmail(string $email): array
    {
        $contacts = [];
        $dbRes = ContactTable::getList([
            'select' => ['ID', 'NAME', 'EMAIL_VALUE' => 'EMAIL.VALUE'],
            'runtime' => [
                new Reference(
                    'EMAIL',
                    FieldMultiTable::class,
                    [
                        '=this.ID' => 'ref.ELEMENT_ID',
                        'ref.ENTITY_ID' => new SqlExpression("'CONTACT'"),
                        '=ref.TYPE_ID' => new SqlExpression("'EMAIL'")
                    ]
                )
            ],
            'filter' => ['=EMAIL.VALUE' => $email],
            'order' => ['DATE_CREATE' => 'ASC'] // Сортируем по дате создания (самый старый первый)
        ]);

        while ($contact = $dbRes->fetch()) {
            $contacts[] = $contact;
        }

        return $contacts;
    }

    /**
     * Извлекает телефоны лида.
     *
     * @param array $fields Данные лида.
     *
     * @return array Массив телефонов.
     */
    private static function extractLeadPhones(array $fields): array
    {
        $phones = [];
        if (!empty($fields['FM']['PHONE'])) {
            foreach ($fields['FM']['PHONE'] as $phoneData) {
                $parsedPhone = Parser::getInstance()->parse($phoneData['VALUE'])->format(Format::E164);
                if ($parsedPhone) {
                    $phones[] = $parsedPhone;
                }
            }
        }
        return $phones;
    }

    /**
     * Фильтрует контакты по телефонам.
     *
     * @param array $contacts Найденные контакты.
     * @param array $leadPhones Телефоны лида.
     *
     * @return array Отфильтрованные контакты.
     */
    private static function filterContactsByPhone(array $contacts, array $leadPhones): array
    {
        $matched = [];
        foreach ($contacts as $contact) {
            $dbPhones = FieldMultiTable::getList([
                'select' => ['VALUE'],
                'filter' => [
                    '=ENTITY_ID' => 'CONTACT',
                    '=ELEMENT_ID' => $contact['ID'],
                    '=TYPE_ID' => 'PHONE'
                ]
            ]);

            while ($phone = $dbPhones->fetch()) {
                $normalizedPhone = Parser::getInstance()->parse($phone['VALUE'])->format(Format::E164);
                if ($normalizedPhone && in_array($normalizedPhone, $leadPhones, true)) {
                    $matched[] = $contact;
                    break;
                }
            }
        }
        return $matched;
    }

    /**
     * Привязывает контакт к лиду.
     *
     * @param int $leadId ID лида.
     * @param int $contactId ID контакта.
     *
     * @return void
     */
    private static function updateLeadContact(int $leadId, int $contactId): void
    {
        $lead = new CCrmLead();
        $updateFields = ['CONTACT_ID' => $contactId];
        $lead->Update($leadId, $updateFields);
    }

    /**
     * Создаёт контакт на основе данных внутреннего лида.
     *
     * @param array $lead Данные лида.
     *
     *  @return void
     */
    private static function createContact(array $lead): void
    {
        // Берём ФИО из лида, если есть
        $name = !empty($lead['NAME']) ? $lead['NAME'] : '';
        $lastName = !empty($lead['LAST_NAME']) ? $lead['LAST_NAME'] : '';
        $secondName = !empty($lead['SECOND_NAME']) ? $lead['SECOND_NAME'] : '';

        $email = trim(reset($lead['FM']['EMAIL'])['VALUE']);
        $email_type = trim(reset($lead['FM']['EMAIL'])['VALUE_TYPE']);

        $contactData = [
            'NAME' => $name,
            'LAST_NAME' => $lastName,
            'SECOND_NAME' => $secondName,
            'ASSIGNED_BY_ID' => 29,
            'MODIFY_BY_ID' => 29
        ];

        $result = ContactTable::add($contactData);
        $id = $result->getId();

        $fields = ['STATUS_ID' => 'CONVERTED', 'CONTACT_ID' => $id];
        $entity = new CCrmLead(false);
        $entity->Update($lead['ID'], $fields, true, true, array());

        $fields = [
            'PARENT_ID_134' => 12,
            "FM" => [
                "EMAIL" => [
                    "n0" => [
                        "VALUE" => $email,
                        "VALUE_TYPE" => $email_type,
                    ],
                ]
            ]
        ];

        $entity = new \CCrmContact(false);
        $entity->Update($id, $fields, true, true, array());
    }

    /**
     * Сливает внутренний лид в некачественные.
     *
     * @param array $lead Данные лида.
     *
     *  @return void
     */
    private static function junkLead(array $lead): void
    {
        $upData =  [
            'STATUS_ID' => 'JUNK',
            'UF_CRM_1713505108' => 50254,
            'MODIFY_BY_ID' => 29,
            'COMMENTS' => 'IBS',
            'UF_CRM_1713504434' => 'IBS',
            'ASSIGNED_BY_ID' => 29
        ];
        $entity = new CCrmLead(false);
        $entity->Update($lead['ID'], $upData, true, true, array());
    }

    /**
     * Запускает процесс обработки лида после привязки контакта.
     *
     * @param int $leadId ID лида.
     * @param int $contactId ID контакта.
     *
     * @return void
     */
    private static function setLeadResponsible(int $leadId, ?int $contactId): void
    {
        include($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/classes/imran/handlers/LeadResponsibleService.php');

        LeadResponsibleService::processLead($leadId, $contactId);
    }
}
