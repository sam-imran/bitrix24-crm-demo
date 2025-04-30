<?php

namespace Sprint\Migration;

use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Internal\EventMessageTable;

class UserInvatiationOff extends Version
{
    protected $description = "Отключение шаблона приглашения на портал";

    protected $moduleVersion = "4.3.1";

    public function up()
    {
        $templates = EventMessageTable::getList([
            'filter' => ['EVENT_NAME' => 'INTRANET_USER_INVITATION'],
            'select' => ['ID'],
        ])->fetchAll();

        foreach ($templates as $template) {
            $result = EventMessageTable::update($template['ID'], ['ACTIVE' => 'N']);
        }

        $templates = EventMessageTable::getList([
            'filter' => ['EVENT_NAME' => 'USER_PASS_REQUEST'],
            'select' => ['ID', 'MESSAGE'],
        ])->fetchAll();

        foreach ($templates as $template) {
            $newMessage = preg_replace("/\s*\#MESSAGE\#\s*\n?/", "\n\n", $template['MESSAGE']);

            if ($newMessage !== $template['MESSAGE']) {
                $result = EventMessageTable::update($template['ID'], ['MESSAGE' => $newMessage]);
            }
        }
    }

    public function down()
    {
        $templates = EventMessageTable::getList([
            'filter' => ['EVENT_NAME' => 'INTRANET_USER_INVITATION'],
            'select' => ['ID'],
        ])->fetchAll();

        foreach ($templates as $template) {
            $result = EventMessageTable::update($template['ID'], ['ACTIVE' => 'Y']);
        }
    }
}
