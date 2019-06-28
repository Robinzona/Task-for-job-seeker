<?php

define(DEBUG_MODE, false);

if (DEBUG_MODE)
{
    // Время жизни воркера в debug-режиме
    $nWorkerLiveTime = 15;
}

// Состояния воркера
define(WORKER_TYPE_HANDLER, 1);
define(WORKER_TYPE_GENERATOR, 2);

// FQDN, под которым запущен полигон
$sDomainName = 'task.webforeverteam.com';

// Параметры соединения с Redis, сейчас на том же локальном сервере
$sRedisScheme = 'tcp';
$sRedisHost = '127.0.0.1';
$nRedisPort = 6379;

// Настройки канала подписки
$sChannelName = 'messagevalues:';

// Настройки генератора
$nGeneratorMinValue     = 0;  // Минимальное генерируемое значение
$nGeneratorMaxValue     = 10; // Максимальное генерируемое значение
$nGeneratorMaxGoodValue = 8;  // Максимальное не ошибочное значение
$nGeneratorDelay        = 10; // Частота генерации значений, секунды
$nGeneratorPingDelay    = 2;  // Частота, с которой генератор рапортует, что ещё жив, секунды

// Убивает воркера
function KillWorker($nWorkerId, $bByTime = false)
{
    global $oRedis;

    // Получаем состояние воркера
    $nWorkerState = $oRedis->hget('worker:' . $nWorkerId, 'state');

    LogEvent('воркер-' . ($nWorkerState == WORKER_TYPE_HANDLER ? 'обработчик' : 'генератор') .
        ' #' . $nWorkerId . ($bByTime ? ' умер по таймеру' : ' был убит вручную') );

    // Если убит генератор - очистить его id
    if ($nWorkerState == WORKER_TYPE_GENERATOR)
    {
        $oRedis->del('generator:id');
        $oRedis->del('generator:pingtime');
    }

    // Удаляем id воркера из множества
    $oRedis->srem('worker:ids', $nWorkerId);

    // Удалим из множества подписчиков
    $oRedis->srem('subscriber:ids', $nWorkerId);

    // Удаляем данные воркера из хэша
    $oRedis->del('worker:' . $nWorkerId);
}

// Сохраняет в лог произошедшее событие
function LogEvent($sEventMessage)
{
    global $oRedis;
    $sCurrentDateTime = date('H:i:s ', time() );
    $oRedis->lpush('log:events', $sCurrentDateTime . $sEventMessage);
}
?>