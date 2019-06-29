<?php
// Подключение файла с настройками
require 'settings.php';

// Соединение с сервером Redis
require 'predis-connect.php';

// Настройки для фоновой работы скрипта
ignore_user_abort(true);
set_time_limit(0);

// Сгенерируем id нового воркера
// В начальный момент worker:maxid отсутствует, вернётся 1
$nWorkerId = $oRedis->incr('worker:maxid');

// Занесём id в множество воркеров, оно используется только
// для возможности убить поток воркера - эмулировать отвал связи
$oRedis->sadd('worker:ids', $nWorkerId);

// Изначально состояние воркера - обработчик
// Сохраним текущее состояние воркера в хэше worker:Nid
$oRedis->hset('worker:' . $nWorkerId, 'state', $nWorkerState = WORKER_TYPE_HANDLER);

// Занесём id в множество обработчиков
$oRedis->sadd('handler:ids', $nWorkerId);

// Залогируем создание нового воркера
LogWorkerEvent('создан воркер-обработчик #' . $nWorkerId);

// Время отправки следующего сообщения в состоянии генератора
$nNextMessageTime = 0;

// Время рапорта генератора, что он ещё жив
$nGeneratorPingTime = 0;

// Время рапорта обработчика, что он ещё жив
$nHandlerPingTime = 0;

// Время старта воркера
$nStartTime = time();

// Запуск бесконечного цикла
while(1)
{
    // Проверка, указали ли мы убить этого воркера
    if (!$oRedis->exists('worker:ids') || !$oRedis->sismember('worker:ids', $nWorkerId) )
    {
        LogWorkerEvent('поток воркера #' . $nWorkerId . ' завершён');
        exit;
    }

    $nCurrentTime = time();

    // Этот воркер - обработчик
    if ($nWorkerState == WORKER_TYPE_HANDLER)
    {
        // Отрапортуем Redis-у, что обработчик ещё жив
        if ($nCurrentTime >= $nHandlerPingTime + $nHandlerPingDelay)
            $oRedis->hset('worker:' . $nWorkerId, 'pingtime', $nHandlerPingTime = $nCurrentTime);

        if (!$oRedis->exists('generator:pingtime') )
            $nGeneratorPingTime = 0;
        else
            $nGeneratorPingTime = $oRedis->get('generator:pingtime');

        // Есть ли уже генератор? Не пропал ли он?
        // В начальный момент generator:id и generator:pingtime отсутствуют
        // Если генератора долго нет - попытаемся стать генератором сами
        if (!$nGeneratorPingTime || ($nCurrentTime > ($nGeneratorPingTime + $nGeneratorPingDelay + 1) ) )
        {
            // Текущий генератор долго не выходил на связь, попытаемся стать генератором сами
            $oRedis->watch('generator:id', 'generator:pingtime');
            if ($nGeneratorPingTime != $oRedis->get('generator:pingtime') )
                $oRedis->unwatch(); // Генератором уже кто-то успел стать, можно уже не пробовать
            else
            {
                $nGeneratorPingTime = time();
                $oRedis->multi();
                // Установим свой id как генератора
                $oRedis->set('generator:id', $nWorkerId);
                // Установим время, когда рапортовали о доступности генератора
                $oRedis->set('generator:pingtime', $nGeneratorPingTime);
                $oRedis->exec();
                $oRedis->unwatch();

                // Проверим, стали ли мы генератором?
                if ( ($nGeneratorId = $oRedis->get('generator:id') ) == $nWorkerId)
                {
                    // Установим время следующей отправки сообщения воркеру-обработчику
                    $nNextMessageTime = $nGeneratorPingTime + $nGeneratorDelay;

                    // Сохраним изменение состояния воркера
                    $oRedis->hset('worker:' . $nWorkerId, 'state', $nWorkerState = WORKER_TYPE_GENERATOR);

                    // Уберём себя из множества обработчиков
                    $oRedis->srem('handler:ids', $nWorkerId);

                    // Залогируем установку нового генератора
                    LogWorkerEvent('воркер #' . $nWorkerId . ' берёт на себя роль генератора');
                }
            }
        }

        // Проверяем, появилось ли необработанное сообщение
        $oRedis->watch('message');
        if ($oRedis->exists('message') && $oRedis->hget('message', 'ready') )
        {
            $oRedis->multi();
            // Установим себя, как обработчика
            $oRedis->hset('message', 'handlerid', $nWorkerId);
            // Уберём признак готовности к обработке
            $oRedis->hset('message', 'ready', false);
            $oRedis->exec();
            $oRedis->unwatch();

            // Проверим, удалось ли нам получить право на обработку сообщения
            if ($nWorkerId == $oRedis->hget('message', 'handlerid') && !$oRedis->hget('message', 'ready') )
            {
                // Получим значение сообщения из хэша
                $nMessageValue = intval($oRedis->hget('message', 'value') );

                // Кто его посылал?
                $nSenderId = $oRedis->hget('message', 'generatorid');

                LogWorkerEvent('обработчик #' . $nWorkerId . ' получил сообщение "' .
                        $nMessageValue . '" от генератора #' . $nSenderId);

                // Сообщение получено, анализируем и обрабатываем
                if ($nMessageValue >= $nGeneratorMinValue && $nMessageValue <= $nGeneratorMaxValue)
                {
                    // Пришло не ошибочное значение
                    if ($nMessageValue <= $nGeneratorMaxGoodValue)
                    {
                        $oRedis->incr('messages:successcount');
                        LogSystemEvent('сообщение "' . $nMessageValue . '" от #' .
                                $nSenderId . ' обработано, счётчик увеличен');
                    }
                    else
                    {
                        $oRedis->incr('messages:errorcount');
                        LogSystemEvent('сообщение "' . $nMessageValue . '" от #' .
                                $nSenderId . ' не обработано, ошибка!');
                    }
                }
                else

                // Очистим обработанное сообщение
                $oRedis->del('message');
            }
        }
        else
            $oRedis->unwatch();

        // Освобождаем процессор от дурной работы на рандомное время в диапазоне 0.3-0.5 сек
        usleep(300000 + rand(0, 200000) );
    }
    // Этот воркер сейчас в режиме генератора
    else
    {
        // Не пришла ли пора рапортовать, что генератор ещё жив и на связи?
        if ($nCurrentTime >= $nGeneratorPingTime + $nGeneratorPingDelay)
        {
            $oRedis->set('generator:pingtime', $nGeneratorPingTime = $nCurrentTime);

            // Заодно здесь же проверим тех обработчиков, которые могли умереть и долго не рапортовали о жизнеспособности
            RescanHandlers();
        }

        // Не пришла ли пора послать новое сообщение на обработку?
        if ($nCurrentTime >= $nNextMessageTime)
        {
            // Тут бы получить просто количество значений в множестве, но пока не понял, какой командой
            $aHandlerIds = $oRedis->smembers('handler:ids');

            // Есть кому его отправить?
            if (is_array($aHandlerIds) && count($aHandlerIds) > 0)
            {
                // Сгенерируем рандомное значение в нужном диапазоне
                $nMessageValue = rand($nGeneratorMinValue, $nGeneratorMaxValue);

                // Установим время следующей отправки сообщения воркеру-обработчику
                $nMessageTime = time();
                $nNextMessageTime = $nMessageTime + $nGeneratorDelay;

                // Добавим данные о сообщении, транзакционно
                $oRedis->multi();
                // Установим значение сообщения в хэше
                $oRedis->hset('message', 'value', $nMessageValue);
                // Установим id генератора
                $oRedis->hset('message', 'generatorid', $nWorkerId);
                // Очистим id обработчика
                $oRedis->hdel('message', 'handlerid');
                // Установим признак готовности к обработке
                $oRedis->hset('message', 'ready', true);
                $oRedis->exec();

                // Залогируем подготовку к обработке нового сообщения
                LogWorkerEvent('генератор #' . $nWorkerId . ' отправил сообщение "' .
                        $nMessageValue . '" обработчикам');
            }
            else
                usleep(100000);
        }

        // Посмотрим, сколько остаётся времени до следующего полезного действия
        $nFreeTime = min($nNextMessageTime, $nGeneratorPingTime + $nGeneratorPingDelay) - time() - 1;
        if ($nFreeTime == 1)
            usleep(300000);
        elseif ($nFreeTime > 1)
            sleep(1);
    }

    // Автоматическое убивание воркера-обработчика в debug-режиме
    if (DEBUG_MODE && $nWorkerState == WORKER_TYPE_HANDLER && time() > $nStartTime + $nWorkerLiveTime)
    {
        KillWorker($nWorkerId, true);
        exit;
    }
}
?>