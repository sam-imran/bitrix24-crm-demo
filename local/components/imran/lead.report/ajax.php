<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Crm\LeadTable;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\Loader;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Engine\Response\Json;
use Bitrix\Main\Engine\Controller;

/**
 * Class LeadReportController
 *
 * Контроллер для получения количества лидов с учетом фильтрации.
 *
 * @package IMRAN
 */
class LeadReportController extends Controller
{
    /**
     * Получает количество лидов с учетом фильтрации.
     *
     * @return void
     */
    public function getRowsCountAction(): void
    {
        Loader::includeModule('crm');

        $filterId = 'LEADS_REPORT_FILTER';
        $filterOptions = new Options($filterId);
        $filterData = $filterOptions->getFilter();
        $filter = [];

        $query = LeadTable::query()->setSelect(['CNT']);
        $query->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'));

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

        $result = $query->exec()->fetch();

        (new Json([
            'status' => 'success',
            'data' => [
                'qty' => (int) $result['CNT']
            ],
        ]))->send();
    }
}
