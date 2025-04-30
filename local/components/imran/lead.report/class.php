<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Crm\LeadTable;
use Bitrix\Crm\DealTable;
use Bitrix\Main\Entity\Query;
use Shuchkin\SimpleXLSXGen;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Crm\StatusTable;
use Bitrix\Main\UserTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\History\Entity\DealStageHistoryTable;
use CUserFieldEnum;
use CCrmStatus;
use CIBlockElement;

/**
 * Class LeadReport
 *
 * Класс выгрузки cквозного отчета по лидам в excel
 *
 * @package IMRAN
 */
class LeadReport extends CBitrixComponent
{
    /**
     * Заголовки отчета по сделкам.
     *
     * @var array
     */
    private array $reportHeaders = [
        'ID Лида',
        'Название Лида',
        'E-mail Лида',
        'Тип Лида',
        'Тег Лида',
        'Дата создания Лида',
        'Стадия Лида',
        'Причина закрытия некачественного Лида',
        'Дата закрытия Лида',
        'Количество дней от создания до закрытия Лида',
        'Количество задач открыто за все время',
        'Количество звонков',
        'Дата последнего изменения',
        'Название связанной с Лидом Сделки',
        'Тип сделки',
        'Ответственный по Сделке',
        'Стадия Сделки',
        'Стадия до отмены',
        'Причина отмены',
        'Сумма выручки (без НДС)',
        'Сумма выручки (без НДС) прогнозная * коэф.',
        'Дата создания Сделки',
        'Дней от создания лида до создания сделки',
        'Дата перехода в стадию “В работе”',
        'Дней в стадии “В работе”',
        'Дата перехода в стадию “Сбор требований”',
        'Дней в стадии “Сбор требований”',
        'Дата перехода в стадию “Сделано КП”',
        'Дней в стадии  “Сделано КП”',
        'Дата перехода в стадию “Работа с возражениями”',
        'Дней в стадии “Работа с возражениями”',
        'Дата перехода в стадию “Соглашение”',
        'Дней в стадии “Соглашение”',
        'Дата акта',
        'Дата закрытия Сделки',
        'Дней от создания Лида до закрытия Сделки',
    ];

    /**
     * Учитываемые стадии сделки.
     *
     * @var array
     */
    private array $stages = ['3', '2', 'PREPARATION', 'PREPAYMENT_INVOICE', 'EXECUTING'];

    /**
     * Выполнение компонента.
     *
     * @return void
     */
    public function executeComponent(): void
    {
        Loader::includeModule('crm');

        $request = Application::getInstance()->getContext()->getRequest();

        $this->arResult['FILTER_ID'] = 'LEADS_REPORT_FILTER';
        $this->arResult['FILTER_FIELDS'] = $this->getFilterFields();
        $this->arResult['FILTER_PRESETS'] = $this->getFilterPresets();
        $this->arResult['FILTER_DATA'] = (
            new Options($this->arResult['FILTER_ID']))->getFilter($this->arResult['FILTER_FIELDS']);

        if ($request['download'] === 'y') {
            $this->downloadReport();
            exit;
        }

        $this->includeComponentTemplate();
    }

    /**
     * Возвращает поля фильтра.
     *
     * @return array
     */
    private function getFilterFields(): array
    {
        return [
            ['id' => 'DATE_CREATE', 'name' => 'Дата создания', 'type' => 'date', 'default' => true],
            [
                'id' => 'STATUS_ID',
                'name' => 'Стадия лида',
                'type' => 'list',
                'default' => true,
                'items' => CCrmStatus::GetStatusList('STATUS'),
                'params' => [
                    'multiple' => 'Y',
                ],
            ],
            [
                'id' => 'SOURCE_ID',
                'name' => 'Источник лида',
                'type' => 'list',
                'default' => true,
                'items' => CCrmStatus::GetStatusList('SOURCE'),
                'params' => [
                    'multiple' => 'Y',
                ],
            ],
            [
                'id' => 'ASSIGNED_BY_ID',
                'name' => 'Ответственный',
                'type' => 'entity_selector',
                'default' => true,
                'params' => [
                    'multiple' => 'Y',
                    'dialogOptions' => [
                        'context' => 'FILTER_ASSIGNED_BY',
                        'entities' => [['id' => 'user'], ['id' => 'department']]
                    ]
                ]
            ]
        ];
    }

    /**
     * Возвращает предустановленные настройки фильтра.
     *
     * @return array
     */
    private function getFilterPresets(): array
    {
        return [
            'default' => [
                'name'   => 'Текущий месяц',
                'default' => 'true',
                'fields' => [
                    'DATE_CREATE_datesel' => 'CURRENT_MONTH',
                ],
            ],
        ];
    }

    /**
     * Выгружает отчет в файл.
     *
     * @return void
     */
    private function downloadReport(): void
    {
        require_once(__DIR__ . '/xls.php');

        $rows = $this->getRows();

        $activities = $this->getActivities(array_column($rows, 'ID'));

        $reasons = $this->getUserFieldIblockValues(55);

        $tags = $this->getUserFieldEnumValues('UF_CRM_1741691268');

        $dealTypes = $this->getUserFieldIblockValues(47);

        $dealReasons = $this->getUserFieldIblockValues(40);

        $dealsSet = array_filter(array_column($rows, 'DEAL_ID'));

        $dealsHistory = (count($dealsSet)) ? $this->getDealsStageHistory($dealsSet) : [];

        $data = [];

        foreach ($this->reportHeaders as $header) {
            $headers[] = "<b>$header</b>";
        }

        $data[] = $headers;

        foreach ($rows as $row) {
            $leadType = match ($row['STATUS_ID']) {
                'INTEREST' => 'Холодный',
                'NEW'      => 'Горячий',
                default    => '',
            };

            $leadCloseDate = $row['UF_CRM_1713503544652'] ? $row['UF_CRM_1713503544652']->format('d.m.Y') : '';

            $daysToClose = $row['UF_CRM_1713503544652'] ?
            max(0, (int) (new DateTime($row['UF_CRM_1713503544652']->format('Y-m-d')))
                ->diff(new DateTime($row['DATE_CREATE']->format('Y-m-d')))
                ->days) : null;

            $leadLastTouch = (
                $activities[$row['ID']]['LAST_TOUCH'] &&
                $activities[$row['ID']]['LAST_TOUCH']->getTimestamp() > $row['DATE_MODIFY']->getTimestamp()) ?
                $activities[$row['ID']]['LAST_TOUCH']->format('d.m.Y') : $row['DATE_MODIFY']->format('d.m.Y');

            $dealResponsible = trim(implode(' ', [
                $row['DEAL_ASSIGNED_LASTNAME'], $row['DEAL_ASSIGNED_NAME'], $row['DEAL_ASSIGNED_SECONDNAME'],
            ]));

            $dealCreated = $row['DEAL_DATE_CREATE'] ? $row['DEAL_DATE_CREATE']->format('d.m.Y') : null;

            $dealSum = (int) $row['DEAL_SUM'] > 0 ? (int) $row['DEAL_SUM'] : null;
            $dealSumExpected = (int) $row['DEAL_SUM_EXPECTED'] > 0 ? (int) $row['DEAL_SUM_EXPECTED'] : null;

            $fromLeadToDeal = $row['DEAL_DATE_CREATE'] ?
            max(0, (int) (new DateTime($row['DEAL_DATE_CREATE']->format('Y-m-d')))
                ->diff(new DateTime($row['DATE_CREATE']->format('Y-m-d')))
                ->days) : null;

            $dealActDate = $row['DEAL_DATE_ACT'] ? $row['DEAL_DATE_ACT']->format('d.m.Y') : null;
            $dealCloseDate = $row['DEAL_DATE_CLOSE'] ? $row['DEAL_DATE_CLOSE']->format('d.m.Y') : null;

            $dealHistory = false;

            if ($dealsHistory[$row['DEAL_ID']]) {
                $dealHistory = $this->getStageDurations($dealsHistory[$row['DEAL_ID']]);
            }

            $workStartDate = isset($dealHistory['3']['DATE']) ?
                $dealHistory['3']['DATE']->format('d.m.Y') : null;
            $workDays = isset($dealHistory['2']['DATE']) ?
                $dealHistory['3']['DAYS_IN_STAGE'] : null;

            $requirementsStartDate = isset($dealHistory['2']['DATE']) ?
                $dealHistory['2']['DATE']->format('d.m.Y') : null;
            $requirementsDays = isset($dealHistory['PREPARATION']['DATE']) ?
                $dealHistory['2']['DAYS_IN_STAGE'] : null;

            $kpStartDate = isset($dealHistory['PREPARATION']['DATE']) ?
                $dealHistory['PREPARATION']['DATE']->format('d.m.Y') : null;
            $kpDays = isset($dealHistory['PREPAYMENT_INVOICE']['DATE']) ?
                $dealHistory['PREPARATION']['DAYS_IN_STAGE'] : null;

            $objectionsStartDate = isset($dealHistory['PREPAYMENT_INVOICE']['DATE']) ?
                $dealHistory['PREPAYMENT_INVOICE']['DATE']->format('d.m.Y') : null;
            $objectionsDays = isset($dealHistory['EXECUTING']['DATE']) ?
                $dealHistory['PREPAYMENT_INVOICE']['DAYS_IN_STAGE'] : null;

            $agreementStartDate = isset($dealHistory['EXECUTING']['DATE']) ?
                $dealHistory['EXECUTING']['DATE']->format('d.m.Y') : null;
            
            $agreementDays = (isset($dealHistory['EXECUTING']['DATE']) && $row['DEAL_DATE_CLOSE']) ?
            max(0, (int) (new DateTime($row['DEAL_DATE_CLOSE']->format('Y-m-d')))
                ->diff(new DateTime($dealHistory['EXECUTING']['DATE']->format('Y-m-d')))
                ->days) : null;

            $fromLeadToWin = ($row['DEAL_DATE_CLOSE']) ?
                max(0, (int) (new DateTime($row['DEAL_DATE_CLOSE']->format('Y-m-d')))
                    ->diff(new DateTime($row['DATE_CREATE']->format('Y-m-d')))
                    ->days) : null;

            $record = [
                $row['ID'], $row['TITLE'], $row['EMAIL'], $leadType, $tags[$row['UF_CRM_1741691268']],
                $row['DATE_CREATE']->format('d.m.Y'), $row['STATUS_NAME'], $reasons[$row['UF_CRM_1713505108']],
                $leadCloseDate, $daysToClose, (int) $activities[$row['ID']]['TASK_COUNT'],
                (int) $activities[$row['ID']]['CALL_COUNT'], $leadLastTouch, $row['DEAL_TITLE'],
                $dealTypes[$row['DEAL_TYPE']], $dealResponsible, $row['DEAL_STAGE_NAME'], $row['DEAL_CANCEL_STAGE'],
                $dealReasons[$row['DEAL_CANCEL_REASON']], $dealSum, $dealSumExpected,
                $dealCreated, $fromLeadToDeal, $workStartDate, $workDays, $requirementsStartDate, $requirementsDays,
                $kpStartDate, $kpDays, $objectionsStartDate, $objectionsDays, $agreementStartDate, $agreementDays,
                $dealActDate, $dealCloseDate, $fromLeadToWin
            ];

            $data[] = $record;
        }

        $xlsx = SimpleXLSXGen::fromArray($data);

        $xlsx->downloadAs('leads.xlsx');
    }

    /**
     * Получает лиды со связими по фильтру.
     *
     * @return array
     */
    private function getRows(): array
    {
        $filterData = $this->arResult['FILTER_DATA'];
        $filter = [];

        $query = new Query(LeadTable::getEntity());

        $query->setSelect([
            'ID',
            'TITLE',
            'EMAIL',
            'DATE_CREATE',
            'DATE_MODIFY',
            'ASSIGNED_BY_ID',
            'STATUS_ID',
            'STATUS_NAME'               => 'STATUS.NAME',
            'UF_CRM_1741691268',
            'UF_CRM_1713505108',
            'UF_CRM_1713503544652',
            'DEAL_ID'                   => 'DEAL.ID',
            'DEAL_TITLE'                => 'DEAL.TITLE',
            'DEAL_DATE_CREATE'          => 'DEAL.DATE_CREATE',
            'DEAL_TYPE'                 => 'DEAL.UF_CRM_1690555011',
            'DEAL_CANCEL_REASON'        => 'DEAL.UF_CRM_1686809705',
            'DEAL_CANCEL_STAGE'         => 'DEAL.UF_CRM_1741690984',
            'DEAL_SUM'                  => 'DEAL.UF_CRM_1699330770',
            'DEAL_SUM_EXPECTED'         => 'DEAL.UF_CRM_1686030678',
            'DEAL_STAGE_ID'             => 'DEAL.STAGE_ID',
            'DEAL_DATE_ACT'             => 'DEAL.UF_CRM_1686027109',
            'DEAL_DATE_CLOSE'           => 'DEAL.UF_CRM_1686836085278',
            'DEAL_STAGE_NAME'           => 'DEAL_STAGE.NAME',
            'DEAL_ASSIGNED_BY_ID'       => 'DEAL.ASSIGNED_BY_ID',
            'DEAL_ASSIGNED_NAME'        => 'DEAL_ASSIGNED.NAME',
            'DEAL_ASSIGNED_LASTNAME'    => 'DEAL_ASSIGNED.LAST_NAME',
            'DEAL_ASSIGNED_SECONDNAME'  => 'DEAL_ASSIGNED.SECOND_NAME',
        ]);

        $query->registerRuntimeField(
            'DEAL',
            [
                'data_type'  => DealTable::getEntity(),
                'reference'  => ['=this.ID' => 'ref.LEAD_ID'],
                'join_type'  => 'LEFT'
            ]
        );

        // Присоединяем таблицу статусов для лида
        $query->registerRuntimeField(
            'STATUS',
            new ReferenceField(
                'STATUS',
                StatusTable::getEntity(),
                [
                    '=this.STATUS_ID' => 'ref.STATUS_ID',
                    '=ref.ENTITY_ID'  => ['?', 'STATUS'] // Ограничиваем выборку статусами для лидов
                ],
                ['join_type' => 'LEFT']
            )
        );

        // Присоединяем таблицу статусов для сделки
        $query->registerRuntimeField(
            'DEAL_STAGE',
            new ReferenceField(
                'DEAL_STAGE',
                StatusTable::getEntity(),
                [
                    '=this.DEAL.STAGE_ID' => 'ref.STATUS_ID',
                    '=ref.ENTITY_ID'       => ['?', 'DEAL_STAGE'] // Ограничиваем выборку статусами для сделок
                ],
                ['join_type' => 'LEFT']
            )
        );

        // Присоединяем ФИО ответственного за сделку
        $query->registerRuntimeField(
            'DEAL_ASSIGNED',
            new ReferenceField(
                'DEAL_ASSIGNED',
                UserTable::getEntity(),
                ['=this.DEAL.ASSIGNED_BY_ID' => 'ref.ID'],
                ['join_type' => 'LEFT']
            )
        );

        // Фильтр по дате создания
        if (!empty($filterData['DATE_CREATE_from']) || !empty($filterData['DATE_CREATE_to'])) {
            $filter['>=DATE_CREATE'] = $filterData['DATE_CREATE_from'];
            $filter['<=DATE_CREATE'] = $filterData['DATE_CREATE_to'];
        }

        // Фильтр по стадии
        if (!empty($filterData['STATUS_ID'])) {
            $filter['@STATUS_ID'] = $filterData['STATUS_ID'];
        }

        // Фильтр по источнику
        if (!empty($filterData['SOURCE_ID'])) {
            $filter['@SOURCE_ID'] = $filterData['SOURCE_ID'];
        }

        // Фильтр по ответственному
        if (!empty($filterData['ASSIGNED_BY_ID'])) {
            $filter['@ASSIGNED_BY_ID'] = $filterData['ASSIGNED_BY_ID'];
        }

        $query->setFilter($filter);
        $query->setOrder(['DATE_CREATE' => 'DESC']);
        $rows = $query->exec()->fetchAll();

        return $rows;
    }

    /**
     * Получает данные по активностям лида.
     *
     * @param array $ids массив ID лидов.
     *
     * @return array
     */
    private function getActivities(array $ids): array
    {
        $types = [
            'TODO' => 'Дело',
            'TASKS_TASK' => 'Задача',
            'CALL' => 'Звонок',
        ];

        $queryActivity = new Query(ActivityTable::getEntity());

        $queryActivity->setSelect([
            'OWNER_ID',
            // Вычисляем максимальную дату модификации как последнее касание
            'LAST_TOUCH' => new ExpressionField('LAST_TOUCH', 'MAX(%s)', ['LAST_UPDATED']),
            // Количество звонков: считаем, когда PROVIDER_ID равен 'CRM_CALL'
            'CALL_COUNT' => new ExpressionField(
                'CALL_COUNT',
                "SUM(CASE WHEN %s = 'CALL' THEN 1 ELSE 0 END)",
                ['PROVIDER_TYPE_ID']
            ),
            // Количество задач: считаем, когда PROVIDER_ID равен 'CRM_TASK'
            'TASK_COUNT' => new ExpressionField(
                'TASK_COUNT',
                "SUM(CASE WHEN %s = 'TASKS_TASK' THEN 1 ELSE 0 END)",
                ['PROVIDER_TYPE_ID']
            ),
        ]);

        $queryActivity->setFilter([
            '=OWNER_TYPE_ID' => 1, // 1 — идентификатор для лидов
            '@OWNER_ID' => $ids,
            'PROVIDER_TYPE_ID' => array_keys($types),
        ]);

        $queryActivity->setGroup(['OWNER_ID']);

        $resultActivity = $queryActivity->exec();

        $activityData = [];
        while ($row = $resultActivity->fetch()) {
            $activityData[$row['OWNER_ID']] = $row;
        }

        return $activityData;
    }

    /**
     * Получает значения для пользовательского поля типа привязка к ИБ.
     *
     * @param int $iblockId ID инфоблока.
     *
     * @return array
     */
    private function getUserFieldIblockValues(int $iblockId): array
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

    /**
     * Получает значения для списочного пользовательского поля CRM.
     *
     * @param string $fieldId Код пользовательского поля (например, 'UF_CRM_1741691268').
     *
     * @return array Возвращает массив значений в формате [ID => VALUE].
     */
    private function getUserFieldEnumValues(string $fieldId): array
    {
        $values = [];

        $res = CUserFieldEnum::GetList([], ['USER_FIELD_NAME' => $fieldId]);
        while ($enum = $res->Fetch()) {
            $values[$enum['ID']] = $enum['VALUE'];
        }

        return $values;
    }

    /**
     * Получает историю смены стадий для нескольких сделок.
     *
     * @param int[] $dealIds Массив ID сделок.
     *
     * @return array Массив с историей смены стадий для каждой сделки.
     */
    private function getDealsStageHistory(array $dealIds): array
    {
        $result = DealStageHistoryTable::getList([
            'select' => ['OWNER_ID', 'STAGE_ID', 'CREATED_TIME'],
            'filter' => [
                '@OWNER_ID' => $dealIds,
                '@STAGE_ID' => $this->stages,
            ],
            'order'  => ['OWNER_ID' => 'ASC', 'CREATED_TIME' => 'ASC']
        ]);

        $history = [];
        while ($row = $result->fetch()) {
            $dealId = (int)$row['OWNER_ID'];

            $history[$dealId][] = $row;
        }

        return $history;
    }

    /**
     * Разбирает историю стадий сделки, формируя массив с датами переходов.
     * Если стадии были пропущены, их дата фиксируется как дата первой непропущенной стадии.
     *
     * @param array $history Массив истории стадий сделки, отсортированный по времени.
     *
     * @return array Возвращает массив с датами переходов для всех стадий сделки.
     */
    private function parseStageHistory(array $history): array
    {
        $expected = $this->stages;
        // Инициируем результат null-значениями
        $result = array_fill_keys($expected, null);
        
        // Заполняем для каждой стадии первую найденную дату из истории
        foreach ($history as $entry) {
            $stage = $entry['STAGE_ID'];
            if (!in_array($stage, $expected, true)) {
                continue;
            }
            if ($result[$stage] === null) {
                $result[$stage] = $entry['CREATED_TIME'];
            }
        }

        // Заполняем пропуски, начиная с конца (берем ближайшую не-пропущенную стадию)
        $lastDate = null;
        for ($i = count($expected) - 1; $i >= 0; $i--) {
            $stage = $expected[$i];
            if ($result[$stage] !== null) {
                $lastDate = $result[$stage];
            } elseif ($lastDate !== null) {
                $result[$stage] = $lastDate;
            }
        }

        return $result;
    }

    /**
     * Вычисляет длительность нахождения сделки в каждой стадии.
     *
     * @param array $history Массив истории стадий сделки, отсортированный по времени.
     *
     * @return array Возвращает массив с длительностью пребывания в каждой стадии.
     */
    private function getStageDurations(array $history): array
    {
        // Получаем даты входа в стадии
        $stageDates = $this->parseStageHistory($history);

        $result = [];

        foreach ($this->stages as $index => $stage) {
            $date = $stageDates[$stage] ?? null;
            $nextStage = $this->stages[$index + 1] ?? null;
            $nextDate = $nextStage ? ($stageDates[$nextStage] ?? null) : null;

            if ($date) {
                $daysInStage = 0;
                if ($nextDate) {
                    $daysInStage = max(
                        0,
                        (new DateTime($nextDate->format('Y-m-d')))
                            ->diff(new DateTime($date->format('Y-m-d')))
                            ->days
                    );
                }

                $result[$stage] = [
                    'DATE' => $date,
                    'DAYS_IN_STAGE' => $daysInStage,
                ];
            }
        }

        return $result;
    }
}
