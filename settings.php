<?php
define('DEBUG_MODE', false);

if (DEBUG_MODE)
{
    // Время жизни воркера в debug-режиме
    $nWorkerLiveTime = 15;
}

// Состояния воркера
define('WORKER_TYPE_HANDLER', 1);
define('WORKER_TYPE_GENERATOR', 2);

// FQDN, под которым запущен полигон
$sDomainName = 'task.webforeverteam.com';

// Параметры соединения с Redis, сейчас на том же локальном сервере
$sRedisScheme = 'tcp';
$sRedisHost = '127.0.0.1';
$nRedisPort = 6379;

// Настройки генератора
$nGeneratorMinValue     =  0;  // Минимальное генерируемое значение сообщения
$nGeneratorMaxValue     = 10;  // Максимальное генерируемое значение сообщения
$nGeneratorMaxGoodValue =  8;  // Максимальное не ошибочное значение сообщения
$nGeneratorDelay        = 10;  // Периодичность генерации значений сообщений, секунды

// Настройки воркеров
$nMaxWorkersCount       = 50;  // Максимальное количество создаваемых воркеров
$nGeneratorPingDelay    =  2;  // Частота, с которой генератор рапортует, что ещё жив, секунды
$nHandlerPingDelay      =  1;  // Частота, с которой обработчик рапортует, что ещё жив, секунды

// Проверяет список обработчиков и удаляет тех, кто долго не рапортовал о жизнеспособности
function RescanHandlers()
{
    global $oRedis, $nHandlerPingDelay;

    // Массив всех id обработчиков, живых и мёртвых
    $aHandlerIds = $oRedis->smembers('handler:ids');

    if (!is_array($aHandlerIds) || !count($aHandlerIds) )
        return;

    $nCurrentTime = time();
    foreach ($aHandlerIds as $nOneId)
    {
        $nHandlerPingTime = $oRedis->hget('worker:' . $nOneId, 'pingtime');

        // Удаляем из Redis пропавшего со связи обработчика
        if ($nCurrentTime > $nHandlerPingTime + 2 * $nHandlerPingDelay)
            KillWorker($nOneId, false, true);
    }

}

// Убивает воркера
function KillWorker($nWorkerId, $bByTime = false, $bByGenerator = false)
{
    global $oRedis;

    // Получаем состояние воркера
    $nWorkerState = $oRedis->hget('worker:' . $nWorkerId, 'state');

    LogWorkerEvent( ($bByGenerator ? 'пропавший ' : 'воркер-') .
            ($nWorkerState == WORKER_TYPE_HANDLER ? 'обработчик' : 'генератор') .
            ' #' . $nWorkerId . ($bByTime ? ' умер по таймеру' :
            ($bByGenerator ? ' очищен генератором' : ' был убит вручную') ) );

    if (!$bByGenerator)
    {
        // Удаляем id воркера из множества
        $oRedis->srem('worker:ids', $nWorkerId);

        // Если убит генератор - очистить его хэш
        if ($nWorkerState == WORKER_TYPE_GENERATOR)
            $oRedis->del('worker:' . $nWorkerId);
    }
    else
    {
        // Удаляем данные воркера из хэша
        $oRedis->del('worker:' . $nWorkerId);

        // Удаляем воркера из обработчиков
        $oRedis->srem('handler:ids', $nWorkerId);
    }
}

// Сохраняет в лог произошедшее с воркером событие
function LogWorkerEvent($sEventMessage)
{
    global $oRedis;
    $sCurrentDateTime = date('H:i:s ', time() );
    $oRedis->rpush('log:events', $sCurrentDateTime . $sEventMessage);
}

// Сохраняет в лог произошедшее системное событие
function LogSystemEvent($sEventMessage)
{
    global $oRedis;
    $sCurrentDateTime = date('H:i:s ', time() );
    $oRedis->rpush('log:messages', $sCurrentDateTime . $sEventMessage);
}
?>