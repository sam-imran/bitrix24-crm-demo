<?php

namespace Imran\Handlers;

use Bitrix\Main\Mail\Event;
use CUser;

/**
 * Класс UserPasswordHandler
 *
 * Отправляет письмо со ссылкой на смену пароля при регистрации нового пользователя.
 *
 * @package IMRAN
 */
class UserPasswordHandler
{
    /**
     * Обработчик события OnAfterUserAdd.
     *
     * @param array $fields Данные о новом пользователе.
     * @return void
     */
    public static function onAfterUserAdd(array $fields): void
    {
        if (empty($fields["EMAIL"])) {
            return;
        }

        $user = new CUser();
        $user->SendPassword(false, $fields["EMAIL"]);
    }
}
