<?php

namespace Imran\Handlers;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Класс LeadTabBuilder
 *
 * Добавляет кастомную вкладку в карточку лида.
 *
 * @package IMRAN
 */
class LeadTabBuilder
{
    /**
     * Обработчик события onEntityDetailsTabsInitialized.
     *
     * @param Event $event
     * @return void
     */
    public static function onLeadShow(Event $event): EventResult
    {
        $tabs = $event->getParameter('TABS');
        $entityID = $event->getParameter('entityID');
        $entityTypeID = $event->getParameter('entityTypeID');

        if ($entityTypeID == \CCrmOwnerType::Lead) {
            // Добавляем свою вкладку в массив вкладок
            $tabs[] = [
                'id' => 'lead_history',
                'name' => 'История взаимодействий',
                'html' =>  '<div class="lead-history-loader"></div>' .
                            '<script>
                                BX.ready(function() {
                                    var loader = new BX.Loader({size: "medium"});
                                    loader.show(document.querySelector(".lead-history-loader"));
                                });
                            </script>',
                'loader' => [
                    'serviceUrl' => '/local/components/imran/lead.history/ajax.php',
                    'componentData' => [
                        'template' => '',
                        'params' => [
                            'ENTITY_ID' => $entityID
                        ]
                    ]
                ]
            ];
        }

        return new EventResult(EventResult::SUCCESS, [
            'tabs' => $tabs,
        ]);
    }
}
