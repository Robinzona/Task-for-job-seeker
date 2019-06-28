<?php
echo 'pos1';
exit;

// Подключение файла с настройками
require 'settings.php';

require 'predis/autoload.php';
Predis\Autoloader::register();

// Создание объекта Redis и коннект к серверу
try { $oRedis = new Predis\Client(['scheme' => $sRedisScheme, 'host' => $sRedisHost, 'port' => $nRedisPort], ['read_write_timeout' => 0]); }
catch (Exception $e) { die($e->getMessage() ); }

print_r($oRedis);
exit;

// Настройки для фоновой работы скрипта
ignore_user_abort(true);
set_time_limit(0);

// Сгенерируем id нового воркера
// В начальный момент worker:maxid отсутствует, вернётся 1
$nWorkerId = $oRedis->incr('worker:maxid');

// Изначально состояние воркера - обработчик
$nWorkerState = WORKER_TYPE_HANDLER;

// Занесём id воркера в множество
$oRedis->sadd('worker:ids', $nWorkerId);

// Сохраним текущее состояние воркера
$oRedis->hset('worker:' . $nWorkerId, 'state', $nWorkerState);

// Залогируем создание нового воркера
LogEvent('создан воркер-обработчик #' . $nWorkerId);

// Время отправки следующего сообщения в состоянии генератора
$nNextMessageTime = 0;

// Время рапорта генератора, что он ещё жив
$nGeneratorPingTime = 0;

// Время рапорта обработчика, что он ещё жив
$nHandlerPingTime = 0;

// Есть ли уже генератор?
// В начальный момент generatorid отсутствует
// Если генератора нет - попытаемся стать генератором сами
$oRedis->watch('generator:id');
if (!($nGeneratorId = $oRedis->get('generator:id') ) )
{
    $oRedis->multi();
    $oRedis->set('generator:id', $nWorkerId);
    $oRedis->exec();
    $oRedis->unwatch();

    // Проверим, стали ли мы генератором?
    if ( ($nGeneratorId = $oRedis->get('generator:id') ) == $nWorkerId)
    {
        // Сохраним текущее состояние воркера
        $oRedis->hset('worker:' . $nWorkerId, 'state', $nWorkerState = WORKER_TYPE_GENERATOR);

        // Залогируем установку нового генератора
        LogEvent('воркер #' . $nWorkerId . ' становится генератором');

        // Установим время, когда рапортовали о доступности генератора
        $oRedis->set('generator:pingtime', $nGeneratorPingTime = time() );

        // Установим время следующей отправки сообщения воркеру-обработчику
        $nNextMessageTime = $nGeneratorPingTime + $nGeneratorDelay;
    }
}
else
    $oRedis->unwatch();

// Время старта воркера
$nStartTime = time();

// Запуск бесконечного цикла
while(1)
{
    // Проверка, указали ли мы убить этого воркера
    if (!$oRedis->exists('worker:ids') || !$oRedis->sismember('worker:ids', $nWorkerId) )
    {
        LogEvent('поток воркера #' . $nWorkerId . ' завершён');
        exit;
    }

    // Этот воркер - обработчик. Не пропал ли уже генератор?
    // Если генератора нет - попытаемся стать генератором сами
    if ($nWorkerState == WORKER_TYPE_HANDLER)
    {
        $nCurrentTime = time();

        // Отрапортуем Redis-у, что обработчик ещё жив
        if ($nHandlerPingTime != $nCurrentTime) )
            $oRedis->hset('worker:' . $nWorkerId, 'pingtime', $nHandlerPingTime = $nCurrentTime);

        if (!$oRedis->exists('generator:pingtime') )
            $nGeneratorPingTime = 0;
        else
            $nGeneratorPingTime = $oRedis->get('generator:pingtime');

//LogEvent('воркер #' . $nWorkerId . ' в позиции 1, $nGeneratorPingTime = ' . $nGeneratorPingTime . ', time = ' . time());


        if (!$nGeneratorPingTime || ($nCurrentTime > ($nGeneratorPingTime + $nGeneratorPingDelay) ) )
        {

//LogEvent('воркер #' . $nWorkerId . ' в позиции 2, generator:id = ' . $oRedis->get('generator:id'));

            // Текущий генератор долго не выходил на связь, попытаемся стать генератором сами
            $oRedis->watch('generator:id');
            if (!($nGeneratorId = intval($oRedis->get('generator:id') ) ) )
            {
                $oRedis->multi();
                $oRedis->set('generator:id', $nWorkerId);
                $oRedis->exec();
                $oRedis->unwatch();

//LogEvent('воркер #' . $nWorkerId . ' в позиции 3, $nGeneratorId = ' . $nGeneratorId);

                // Проверим, стали ли мы генератором?
                if ( ($nGeneratorId = $oRedis->get('generator:id') ) == $nWorkerId)
                {
                    // Сохраним текущее состояние воркера
                    $oRedis->hset('worker:' . $nWorkerId, 'state', $nWorkerState = WORKER_TYPE_GENERATOR);

                    // Залогируем установку нового генератора
                    LogEvent('воркер #' . $nWorkerId . ' становится генератором');

                    // Установим время, когда рапортовали о доступности генератора
                    $oRedis->set('generator:pingtime', $nGeneratorPingTime = time() );

                    // Установим время следующей отправки сообщения воркеру-обработчику
                    $nNextMessageTime = $nGeneratorPingTime + $nGeneratorDelay;
                }
            }
            else
                $oRedis->unwatch();

//LogEvent('воркер #' . $nWorkerId . ' в позиции 4, $nGeneratorId = ' . $nGeneratorId);

        }
        else
        {
            // Войдём в режим подписки и будем ждать сообщения от генератора
            $nSubscriptionTime = time();

//LogEvent('воркер #' . $nWorkerId . ' в позиции 5, $nSubscriptionTime = ' . $nSubscriptionTime);


            if ($oPubSub = $oRedis->pubSubLoop() )
            {
                //$oRedis->multi();
                // Добавим id воркера в множество подписавшихся
                $oRedis->sadd('subscriber:ids', $nWorkerId);

                // Подписываемся на рассылку
                $oPubSub->subscribe($sChannelName . $nWorkerId);
                //$oRedis->exec();

                $nMessageValue = -1;
                $sReceiveMessage = '';
                foreach ($oPubSub as $oMessage)
                {   // Видимо, это программный вечный цикл

                    // $oMessage->kind, $oMessage->channel, $oMessage->payload
                    if ( ($oMessage->kind == 'message') && ($nMessageValue = intval($oMessage->payload) ) >= 0)
                    {
                        $sReceiveMessage = 'воркер #' . $nWorkerId . ' получил сообщение "' . $oMessage->payload . '"';
                        break;
                    }

                    if (time() > $nSubscriptionTime)
                        break;

                    // Освобождаем процессор от дурной работы на 0.05 сек
                    usleep(50000);
                }
                $oPubSub->unsubscribe($sChannelName . $nWorkerId);

                // А вот после отписки уже можно посылать другие команды в Redis!

                // Удалим id воркера из множества подписавшихся
                $oRedis->srem('subscriber:ids', $nWorkerId);

                if ($sReceiveMessage)
                    LogEvent($sReceiveMessage);

                // Сообщение получено, анализируем и обрабатываем
                if ($nMessageValue >= $nGeneratorMinValue && $nMessageValue <= $nGeneratorMaxValue)
                {
                    // Пришло не ошибочное значение
                    if ($nMessageValue <= $nGeneratorMaxGoodValue)
                    {
                        $oRedis->incr('messagescount');
                        LogEvent('сообщение обработано, счётчик увеличен');
                    }
                    else
                        LogEvent('сообщение не обработано, выполнение считается ошибочным');
                }

                unset($oPubSub);
            }


//LogEvent('воркер #' . $nWorkerId . ' в позиции 6, прошло ' . (time() - $nSubscriptionTime) . ' сек');
        }
    }
    // Этот воркер сейчас в режиме генератора
    else
    {
        // Не пришла ли пора рапортовать, что генератор ещё жив и на связи?
        if ( ($nCurrentTime = time() ) >= $nGeneratorPingTime + $nGeneratorPingDelay)
            $oRedis->set('generator:pingtime', $nGeneratorPingTime = $nCurrentTime);

        // Не пришла ли пора послать новое сообщение на обработку?
        if ($nCurrentTime >= $nNextMessageTime)
        {
            // Сгенерируем рандомное значение в нужном диапазоне
            $nMessageValue = rand($nGeneratorMinValue, $nGeneratorMaxValue);

            // Есть кому его отправить?
            if ($nReceiverId = $oRedis->srandmember('subscriber:ids') )
            {
                // Должно вернуться количество получивших сообщение
                // По сути - подтверждение о доставке
                $nReceiveCount = $oRedis->publish($sChannelName . $nReceiverId, $nMessageValue);

                // Залогируем установку нового генератора
                LogEvent('воркер-генератор #' . $nWorkerId . ' отправил сообщение "' .
                        $nMessageValue . '" обработчику #' . $nReceiverId .
                        ($nReceiveCount > 0 ? ' - получено успешно' : ' - не доставлено') );

                // Установим время следующей отправки сообщения воркеру-обработчику
                if ($nReceiveCount > 0)
                    $nNextMessageTime = time() + $nGeneratorDelay;
            }

            // Посмотрим, сколько остаётся времени до следующего полезного действия
            $nFreeTime = time() - min($nNextMessageTime, $nGeneratorPingTime + $nGeneratorPingDelay) - 1;
            if ($nFreeTime > 0)
                sleep($nFreeTime);
        }
    }

    // Автоматическое убивание воркера-обработчика в debug-режиме
    if (DEBUG_MODE && $nWorkerState == WORKER_TYPE_HANDLER && time() > $nStartTime + $nWorkerLiveTime)
    {
        KillWorker($nWorkerId, true);
        exit;
    }

//    // Освобождаем процессор от дурной работы на рандомное время, не более 0.1-0.15 сек
//    usleep(100000 + rand(0, 50000) );
}
?>