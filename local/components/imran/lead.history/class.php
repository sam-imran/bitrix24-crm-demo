<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Crm\LeadTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\ContactTable;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Voximplant\StatisticTable;
use Bitrix\Main\Type\DateTime as BitrixDateTime;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\SystemException;
use Bitrix\Main\ArgumentNullException;

Extension::load('ui.dialogs.messagebox');

/**
 * Class MyCrmComponent
 *
 * Компонент для отображения связей сущностей CRM.
 *
 * @package MyCrmComponent
 */
class MyCrmComponent extends CBitrixComponent
{
    /**
     * Подготовка параметров компонента.
     *
     * @param array $params Параметры компонента.
     *
     * @return array
     */
    public function onPrepareComponentParams($params): array
    {
        $request = Application::getInstance()->getContext()->getRequest();

        if (empty($params['ENTITY_ID'])) {
            // Предполагается, что параметр передается через GET-параметры, например, PARAMS[params][ENTITY_ID]
            $entityId = $request->get('PARAMS')['params']['ENTITY_ID'] ?? null;
            if (!$entityId) {
                throw new ArgumentNullException('ENTITY_ID');
            }
            $params['ENTITY_ID'] = (int)$entityId;
        }

        return $params;
    }

    /**
     * Основной метод выполнения компонента.
     *
     * @return void
     */
    public function executeComponent(): void
    {
        $cache = Cache::createInstance();

        if ($cache->initCache($this->arParams['CACHE_TIME'], 'deal_history_' . $this->arParams['ENTITY_ID'])) {
            $this->arResult = $cache->getVars();
        } elseif ($cache->startDataCache()) {
            Loader::includeModule('crm');
            Loader::includeModule('tasks');
            Loader::includeModule('voximplant');
            $this->arResult = $this->getGridData();
            $cache->endDataCache($this->arResult);
        }

        $this->includeComponentTemplate();
    }

    /**
     * Формирование данных для грида.
     *
     * @return array
     */
    private function getGridData(): array
    {
        $cols = [
            ['id' => 'ID', 'name' => 'ID', 'default' => true],
            ['id' => 'TITLE', 'name' => 'Наименование', 'default' => true],
            ['id' => 'ENTITY_TYPE', 'name' => 'Тип Сущности', 'default' => true],
            ['id' => 'STAGE_NAME', 'name' => 'Стадия', 'default' => true],
            ['id' => 'DATE_CREATE', 'name' => 'Дата Создания', 'default' => true],
            ['id' => 'DATE_UPDATE', 'name' => 'Дата последней активности', 'default' => true],
            ['id' => 'ACTIVITIES', 'name' => 'Дела', 'default' => true],
        ];

        $rows = [];
        $entityId = (int)($this->arParams['ENTITY_ID']);
        $data = $this->gatherEntityRelations($entityId);

        foreach ($data as $row) {
            $rows[] = [
                'id'      => $row['ID'],
                'columns' => $row,
            ];
        }

        return ['COLUMNS' => $cols, 'ROWS' => $rows];
    }

    /**
     * Получает данные лида по его ID.
     *
     * @param int $leadId ID лида.
     *
     * @return array|null Массив данных лида или null, если не найден.
     */
    private function getLeadData(int $leadId): ?array
    {
        try {
            $lead = LeadTable::getList([
                'filter' => ['=ID' => $leadId],
                'select' => [
                    'ID',
                    'TITLE',
                    'STATUS_ID',
                    'STATUS_NAME' => 'STATUS.NAME',
                    'DATE_CREATE',
                    'DATE_MODIFY',
                    'CONTACT_ID',
                    'EMAIL'
                ],
                'runtime' => [
                    new ReferenceField(
                        'STATUS',
                        StatusTable::class,
                        [
                            '=this.STATUS_ID' => 'ref.STATUS_ID',
                            '=ref.ENTITY_ID' => new SqlExpression('?s', 'STATUS')
                        ],
                        ['join_type' => 'LEFT']
                    )
                ]
            ])->fetch();

            return $lead ?: null;
        } catch (SystemException $e) {
            echo $e->getMessage();
        }
        return null;
    }

    /**
     * Получает данные сделки, привязанной к лиду, по полю LEAD_ID.
     *
     * @param int $leadId ID лида.
     *
     * @return array|null Массив данных сделки или null, если сделка не найдена.
     */
    private function getDealByLeadId(int $leadId): ?array
    {
        try {
            $deal = DealTable::getList([
                'filter' => ['=LEAD_ID' => $leadId],
                'select' => [
                    'ID',
                    'TITLE',
                    'STAGE_ID',
                    'STAGE_NAME' => 'STATUS.NAME',
                    'DATE_CREATE',
                    'DATE_MODIFY'
                ],
                'runtime' => [
                    new ReferenceField(
                        'STATUS',
                        StatusTable::class,
                        [
                            '=this.STAGE_ID' => 'ref.STATUS_ID',
                            '=ref.ENTITY_ID' => new SqlExpression('?s', 'DEAL_STAGE')
                        ],
                        ['join_type' => 'LEFT']
                    )
                ]
            ])->fetch();

            return $deal ?: null;
        } catch (SystemException $e) {
            echo $e->getMessage();
            return null;
        }
    }

    /**
     * Получает данные контакта по его ID.
     *
     * @param int $contactId ID контакта.
     *
     * @return array|null Массив данных контакта или null, если контакт не найден.
     */
    private function getContactById(int $contactId): ?array
    {
        try {
            $contact = ContactTable::getList([
                'filter' => ['=ID' => $contactId],
                'select' => [
                    'ID',
                    'NAME',
                    'LAST_NAME',
                    'SECOND_NAME',
                    'DATE_CREATE',
                    'DATE_MODIFY',
                    'EMAIL'
                ]
            ])->fetch();

            return $contact ?: null;
        } catch (SystemException $e) {
            echo $e->getMessage();
            return null;
        }
    }

    /**
     * Получает сделки, связанные с контактом, за исключением указанной сделки.
     *
     * @param int      $contactId    ID контакта.
     * @param int|null $excludeDealId ID сделки, которую необходимо исключить.
     *
     * @return array Массив сделок.
     */
    private function getDealsByContact(int $contactId, ?int $excludeDealId = null): array
    {
        try {
            $filter = ['=CONTACT_ID' => $contactId];

            if ($excludeDealId) {
                $filter['!=ID'] = $excludeDealId;
            }

            $deals = DealTable::getList([
                'filter' => $filter,
                'select' => [
                    'ID',
                    'TITLE',
                    'STAGE_ID',
                    'STAGE_NAME' => 'STATUS.NAME',
                    'DATE_CREATE',
                    'DATE_MODIFY'
                ],
                'order' => [
                    'DATE_CREATE' => 'DESC',
                ],
                'runtime' => [
                    new ReferenceField(
                        'STATUS',
                        StatusTable::class,
                        [
                            '=this.STAGE_ID' => 'ref.STATUS_ID',
                            '=ref.ENTITY_ID' => new SqlExpression('?s', 'DEAL_STAGE')
                        ],
                        ['join_type' => 'LEFT']
                    )
                ]
            ])->fetchAll();

            return $deals;
        } catch (SystemException $e) {
            echo $e->getMessage();
            return [];
        }
    }

    /**
     * Получает лиды с заданным email, исключая лид с указанным ID.
     *
     * @param int    $leadId ID лида, который необходимо исключить.
     * @param string $email  Email для поиска.
     *
     * @return array Массив найденных лидов.
     */
    private function getLeadsByEmailExcept(int $leadId, string $email): array
    {
        try {
            $leads = [];
            $dbLeads = LeadTable::getList([
                'filter' => [
                    '=EMAIL' => $email,
                    '!=ID' => $leadId,
                ],
                'order' => [
                    'DATE_CREATE' => 'DESC',
                ],
                'select' => [
                    'ID',
                    'TITLE',
                    'STATUS_ID',
                    'STATUS_NAME' => 'STATUS.NAME',
                    'DATE_CREATE',
                    'DATE_MODIFY',
                    'EMAIL'
                ],
                'runtime' => [
                    new ReferenceField(
                        'STATUS',
                        StatusTable::class,
                        [
                            '=this.STATUS_ID' => 'ref.STATUS_ID',
                            '=ref.ENTITY_ID' => new SqlExpression('?s', 'STATUS')
                        ],
                        ['join_type' => 'LEFT']
                    )
                ]
            ]);

            while ($lead = $dbLeads->fetch()) {
                $leads[] = $lead;
            }

            return $leads;
        } catch (SystemException $e) {
            echo $e->getMessage();
            return [];
        }
    }

    /**
     * Получает активности, связанные с сущностью (лидом или сделкой).
     *
     * @param int $entityId ID сущности.
     *
     * @return string|null HTML-код с таблицей активностей или null, если активностей нет.
     */
    private function getEntityActivities(int $entityId): array
    {
        $types = [
            'TODO' => 'Дело',
            'TASKS_TASK' => 'Задача',
            'CALL' => 'Звонок',
        ];

        $activities = [];
        $lastActivity = false;
        
        try {
            $rs = ActivityTable::getList([
                'filter' => [
                    '=OWNER_ID' => $entityId,
                    'PROVIDER_TYPE_ID' => array_keys($types),
                ],
                'order' => [
                    'LAST_UPDATED' => 'DESC',
                ],
                'select' => [
                    'ID',
                    'PROVIDER_TYPE_ID',
                    'SUBJECT',
                    'COMPLETED',
                    'CREATED',
                    'LAST_UPDATED',
                    'END_TIME'
                ],
            ]);
            
            while ($item = $rs->fetch()) {
                if (!$lastActivity) {
                    $lastActivity = $item['LAST_UPDATED'];
                }
                if ($item['PROVIDER_TYPE_ID'] === 'CALL') {
                    $callData = StatisticTable::getList([
                        'select' => ['CALL_STATUS'],
                        'filter' => [
                            'CRM_ACTIVITY_ID' => $item['ID'],
                        ],
                    ])->fetch();
                    $callStatus = $callData['CALL_STATUS'] ?? null;
                    $item['STATUS'] = ($callStatus == 1) ? 'Завершен' : 'Недозвон';
                } elseif ($item['PROVIDER_TYPE_ID'] === 'TASKS_TASK') {
                    $item['STATUS'] = ($item['COMPLETED'] === 'Y') ? 'Завершена' : 'В работе';
                } else {
                    $item['STATUS'] = ($item['COMPLETED'] === 'Y') ? 'Завершено' : 'В работе';
                }

                $activities[] = [
                    'ID'          => $item['ID'],
                    'TITLE'       => $item['SUBJECT'],
                    'ENTITY_TYPE' => $types[$item['PROVIDER_TYPE_ID']],
                    'STATUS'      => $item['STATUS'],
                    'DATE_CREATE' => $item['CREATED']->format('d.m.Y'),
                    'END_TIME'    => $item['COMPLETED'] === 'Y' && $item['END_TIME'] ?
                                     $item['END_TIME']->format('d.m.Y') : '',
                ];
            }
        } catch (SystemException $e) {
            echo $e->getMessage();
        }

        if ($activities) {
            $html = '<a class="activities-link" href="javascript:void(0);">Посмотреть</a>';
            $html .= '<div style="display: none;">';
            $html .= '<div class="activities">';
            $html .= '<table class="activities-table">';
            $html .= '<thead>';
            $html .= '<tr>';
            $html .= '<th>Наименование</th>';
            $html .= '<th>Тип сущности</th>';
            $html .= '<th>Статус</th>';
            $html .= '<th>Дата создания</th>';
            $html .= '<th>Дата завершения</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';
            foreach ($activities as $activity) {
                $html .= '<tr>';
                $html .= '<td>' . $activity['TITLE'] . '</td>';
                $html .= '<td>' . $activity['ENTITY_TYPE'] . '</td>';
                $html .= '<td>' . $activity['STATUS'] . '</td>';
                $html .= '<td>' . $activity['DATE_CREATE'] . '</td>';
                $html .= '<td>' . $activity['END_TIME'] . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
            $html .= '</div>';

            return [
                'lastActivity' => $lastActivity,
                'html' => $html,
            ];
        }

        return [
            'lastActivity' => false,
            'html' => '',
        ];
    }

    /**
     * Собирает связи для лида.
     *
     * @param int $leadId ID лида.
     *
     * @return array Массив сущностей.
     */
    private function gatherEntityRelations(int $leadId): array
    {
        $result = [];

        // 1. Лид
        $lead = $this->getLeadData($leadId);
        if ($lead) {
            $activities = $this->getEntityActivities($lead['ID']);
            $lastActivity = 
                ($activities['lastActivity'] && ($activities['lastActivity']->getTimestamp() > $lead['DATE_MODIFY']->getTimestamp())) ?
                $activities['lastActivity'] : $lead['DATE_MODIFY'];

            $result[] = [
                'ID'          => $lead['ID'],
                'TITLE'       => '<a href="/crm/lead/details/' . $lead['ID'] . '/">' . $lead['TITLE'] . '</a>',
                'ENTITY_TYPE' => 'Лид',
                'STAGE_NAME'  => $lead['STATUS_NAME'] ?? '',
                'DATE_CREATE' => $lead['DATE_CREATE']->format('d.m.Y'),
                'DATE_UPDATE' => $lastActivity,
                'ACTIVITIES'  => $activities['html'],
            ];
        }

        // 2. Сделка по LEAD_ID
        $deal = $this->getDealByLeadId($leadId);
        if ($deal) {
            $activities = $this->getEntityActivities($deal['ID']);
            $lastActivity = 
                ($activities['lastActivity'] && ($activities['lastActivity']->getTimestamp() > $deal['DATE_MODIFY']->getTimestamp())) ?
                $activities['lastActivity'] : $deal['DATE_MODIFY'];

            $result[] = [
                'ID'          => $deal['ID'],
                'TITLE'       => '<a href="/crm/deal/details/' . $deal['ID'] . '/">' . $deal['TITLE'] . '</a>',
                'ENTITY_TYPE' => 'Сделка',
                'STAGE_NAME'  => $deal['STAGE_NAME'] ?? '',
                'DATE_CREATE' => $deal['DATE_CREATE']->format('d.m.Y'),
                'DATE_UPDATE' => $lastActivity,
                'ACTIVITIES'  => $activities['html'],
            ];
        }

        // 3. Контакт лида (если есть)
        $contact = null;
        if (!empty($lead['CONTACT_ID'])) {
            $contact = $this->getContactById((int)$lead['CONTACT_ID']);
            if ($contact) {
                $fio = $contact['NAME'];
                if (!empty($contact['SECOND_NAME'])) {
                    $fio .= ' ' . $contact['SECOND_NAME'];
                }
                if (!empty($contact['LAST_NAME'])) {
                    $fio = $contact['LAST_NAME'] . ' ' . $fio;
                }
                $result[] = [
                    'ID'          => $contact['ID'],
                    'TITLE'       => '<a href="/crm/contact/details/' . $contact['ID'] . '/">' . trim($fio) . '</a>',
                    'ENTITY_TYPE' => 'Контакт',
                    'STAGE_NAME'  => '',
                    'DATE_CREATE' => $contact['DATE_CREATE']->format('d.m.Y'),
                    'DATE_UPDATE' => $contact['DATE_MODIFY'],
                    'ACTIVITIES'  => '',
                ];

                // 4. Сделки, связанные с контактом (кроме сделки по лиду)
                $contactDeals = $this->getDealsByContact((int)$contact['ID'], $deal ? (int)$deal['ID'] : null);
                if (!empty($contactDeals)) {
                    foreach ($contactDeals as $contactDeal) {
                        $activities = $this->getEntityActivities($contactDeal['ID']);
                        $lastActivity = 
                        ($activities['lastActivity'] && ($activities['lastActivity']->getTimestamp() > $contactDeal['DATE_MODIFY']->getTimestamp())) ?
                            $activities['lastActivity'] : $contactDeal['DATE_MODIFY'];

                        $result[] = [
                            'ID'          => $contactDeal['ID'],
                            'TITLE'       => '<a href="/crm/deal/details/' . $contactDeal['ID'] . '/">' .
                                             $contactDeal['TITLE'] . '</a>',
                            'ENTITY_TYPE' => 'Сделка',
                            'STAGE_NAME'  => $contactDeal['STAGE_NAME'] ?? '',
                            'DATE_CREATE' => $contactDeal['DATE_CREATE']->format('d.m.Y'),
                            'DATE_UPDATE' => $lastActivity,
                            'ACTIVITIES'  => $activities['html'],
                        ];
                    }
                }
            }
        }

        // 5. Лиды с таким же email
        $email = null;
        if (!empty($lead['EMAIL'])) {
            $email = $lead['EMAIL'];
        } elseif ($contact && !empty($contact['EMAIL'])) {
            $email = $contact['EMAIL'];
        }
        if ($email) {
            $relatedLeads = $this->getLeadsByEmailExcept($leadId, $email);
            if (!empty($relatedLeads)) {
                foreach ($relatedLeads as $relatedLead) {
                    $activities = $this->getEntityActivities($relatedLead['ID']);
                    $lastActivity = 
                        ($activities['lastActivity'] && ($activities['lastActivity']->getTimestamp() > $relatedLead['DATE_MODIFY']->getTimestamp())) ?
                        $activities['lastActivity'] : $relatedLead['DATE_MODIFY'];
                        
                    $result[] = [
                        'ID'          => $relatedLead['ID'],
                        'TITLE'       => '<a href="/crm/lead/details/' . $relatedLead['ID'] . '/">' .
                                         $relatedLead['TITLE'] . '</a>',
                        'ENTITY_TYPE' => 'Лид',
                        'STAGE_NAME'  => $relatedLead['STATUS_NAME'] ?? '',
                        'DATE_CREATE' => $relatedLead['DATE_CREATE']->format('d.m.Y'),
                        'DATE_UPDATE' => $lastActivity,
                        'ACTIVITIES'  => $activities['html'],
                    ];
                }
            }
        }

        usort($result, [$this, 'sortEntities']);

        foreach ($result as &$entity) {
            $entity['DATE_UPDATE'] = $entity['DATE_UPDATE']->format('d.m.Y');
        }

        return $result;
    }

    /**
     * Сортирует сущности по дате обновления (новые – в начале).
     *
     * @param array $a Первая сущность.
     * @param array $b Вторая сущность.
     *
     * @return int
     */
    private function sortEntities(array $a, array $b): int
    {
        return $b['DATE_UPDATE']->getTimestamp() <=> $a['DATE_UPDATE']->getTimestamp();
    }
}
