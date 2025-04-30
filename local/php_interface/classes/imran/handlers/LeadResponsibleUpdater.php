<?php

namespace Imran\Handlers;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Crm\LeadTable;
use Bitrix\Main\UserTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Tasks\Internals\TaskTable;
use \Bitrix\Voximplant\Model\CallTable;
use Bitrix\Main\Event;

/**
 * Класс LeadResponsibleUpdater
 *
 * Отвечает за функционал автоматического назначения ответственного в Лиде
 * при первой активности (дело, задача, звонок).
 */
class LeadResponsibleUpdater
{
    /**
     * Обработчик события добавления дела.
     *
     * @param int $activityId ID добавленного дела.
     *
     * @return void
     */
    public static function onActivityAdd(int $activityId): void
    {
        Loader::includeModule('crm');

        // Загружаем данные о деле
        $activity = ActivityTable::getById($activityId)->fetch();
        if (!$activity) {
            return;
        }

        // Проверяем, проходит ли активность проверку
        if (!self::isValidActivity($activity)) {
            return;
        }

        // получаем ID лида и ответственного за дело
        $leadId = (int)$activity['OWNER_ID'];
        $responsibleId = (int)$activity['RESPONSIBLE_ID'];

        // Назначаем нового ответственного за лид
        self::updateLeadResponsible($leadId, $responsibleId);
    }

    /**
     * Обработчик события добавления задачи.
     *
     * @param int   $taskId Идентификатор созданной задачи.
     * @param array $fields Массив полей задачи.
     *
     * @return void
     */
    public static function onTaskAdd(int $taskId, array $fields): void
    {
        Loader::includeModule('tasks');
        Loader::includeModule('crm');

        // Проверяем наличие связей с элементами CRM
        if (empty($fields['UF_CRM_TASK']) || !is_array($fields['UF_CRM_TASK'])) {
            return;
        }

        // Ищем связь с лидом
        foreach ($fields['UF_CRM_TASK'] as $binding) {
            // Проверяем, начинается ли строка с 'L_' (привязка к лиду)
            if (strpos($binding, 'L_') === 0) {
                // Извлекаем ID лида
                $leadId = (int)substr($binding, 2);

                // Формируем массив $activity для проверки
                $activity = [
                    'OWNER_TYPE_ID' => \CCrmOwnerType::Lead,
                    'OWNER_ID' => $leadId,
                    'RESPONSIBLE_ID' => (int)$fields['RESPONSIBLE_ID'],
                    'AUTHOR_ID' => (int)$fields['CREATED_BY'],
                ];

                // Проверяем, проходит ли задача проверку
                if (self::isValidActivity($activity)) {
                    // Назначаем нового ответственного за лид
                    self::updateLeadResponsible($leadId, $activity['RESPONSIBLE_ID']);
                }

                break;
            }
        }
    }

    /**
     * Обработчик события инициализации звонка.
     *
     * @param Event $event Событие звонка.
     *
     * @return void
     */
    public static function onCallEnd(Event $event): void
    {
        Loader::includeModule('crm');

        // Получаем параметры звонка
        $parameters = $event->getParameters();
        $callId = $parameters['CALL_ID'];

        // Получаем данные звонка из таблицы b_voximplant_call
        $callData = self::getCallDataByCallId($callId);

        if (!$callData) {
            return;
        }

        $userId = (int)$callData['USER_ID'];
        $crmBindingsSerialized = $callData['CRM_BINDINGS'];

        // Десериализуем привязки CRM
        $crmBindings = unserialize($crmBindingsSerialized);
        if (!is_array($crmBindings)) {
            return;
        }

        $leadId = null;
        // Ищем привязку, где OWNER_TYPE_ID соответствует типу лида
        foreach ($crmBindings as $binding) {
            if (isset($binding['OWNER_TYPE_ID']) && $binding['OWNER_TYPE_ID'] == \CCrmOwnerType::Lead) {
                $leadId = (int)$binding['OWNER_ID'];
                break;
            }
        }

        if (!$leadId) {
            return;
        }

        // Формируем массив $activity для проверки
        $activity = [
            'OWNER_TYPE_ID'   => \CCrmOwnerType::Lead,
            'OWNER_ID'        => $leadId,
            'RESPONSIBLE_ID'  => $userId,
            'AUTHOR_ID'       => $userId,
        ];

        // Если активность проходит проверку, обновляем ответственного за лид
        if (self::isValidActivity($activity)) {
            self::updateLeadResponsible($leadId, $activity['RESPONSIBLE_ID']);
        }
    }

    /**
     * Проверяет, подходит ли активность для смены ответственного
     *
     * @param array $activity Данные активности
     *
     * @return bool
     */
    private static function isValidActivity(array $activity): bool
    {
        // Проверяем, связано ли дело с лидом
        if ($activity['OWNER_TYPE_ID'] != \CCrmOwnerType::Lead) {
            return false;
        }

        // Загружаем лид
        $lead = LeadTable::getById((int)$activity['OWNER_ID'])->fetch();
        if (!$lead) {
            return false;
        }

        // Проверяем, что текущий ответственный за лид — Bot Сайт IBS Training Center (29)
        if ((int)$lead['ASSIGNED_BY_ID'] !== 29) {
            return false;
        }

        // Загружаем данные об инициаторе
        $initiatorId = (int)$activity['AUTHOR_ID'];

        $initiator = UserTable::getList([
            'filter' => [
                'ID' => $initiatorId
            ],
            'select' => ['ID', 'UF_DEPARTMENT']])->fetch();

        if (!$initiator) {
            return false;
        }

        // Проверяем, не является ли инициатор Начальником отдела продаж (ID 8)
        if ($initiatorId === 8) {
            return false;
        }

        // Проверяем, не является ли инициатор администратором (группа 1)
        $initiatorGroups = \CUser::GetUserGroup($initiatorId);

        if (in_array(1, $initiatorGroups)) {
            return false;
        }

        // Проверяем, не входит ли инициатор в подразделение Отдел автоматизации (ID 19)
        if (isset($initiator['UF_DEPARTMENT']) && in_array(19, $initiator['UF_DEPARTMENT'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Обновляет ответственного у лида
     *
     * @param int $leadId ID лида
     * @param int $responsibleId ID нового ответственного
     * @return bool Успешно ли обновлено
     */
    private static function updateLeadResponsible(int $leadId, int $responsibleId): bool
    {
        $lead = new \CCrmLead();
        $param = ['ASSIGNED_BY_ID' => $responsibleId];
        $result = $lead->Update($leadId, $param);

        return (bool)$result;
    }

    /**
     * Получает данные звонка по CALL_ID из таблицы b_voximplant_call.
     *
     * @param string $callId Идентификатор звонка (CALL_ID).
     *
     * @return array|null Массив с данными звонка или null, если запись не найдена.
     */
    private static function getCallDataByCallId(string $callId): ?array
    {
        Loader::includeModule('voximplant');
        $result = CallTable::getList([
            'filter' => [
                'CALL_ID' => $callId
            ]
        ]);
        return $result->fetch() ?: null;
    }
}
