<?php
require 'settings.php';

$sTask = $_POST ['task'];
$nWorkerId = $_POST ['wid'];

$nLoadedEventsCount = intval($_POST ['eventscount']);
$nLoadedMessagesCount = intval($_POST ['messagescount']);

if (!$sTask)
{
    // Если не указана конкретная задача - просто показать html-шаблон
    header('Content-type: text/html; charset=utf-8');
    require '_template/design.tpl';
    exit;
}

// Соединение с сервером Redis
require 'predis-connect.php';

header('Content-type: text/javascript; charset=utf-8');
$aAnswer = array('task' => $sTask);

if ($sTask == 'addworker')
{
    // Подсчитаем, сколько сейчас воркеров, не превышаем ли лимиты
    if (count($oRedis->smembers('worker:ids') ) >= $nMaxWorkersCount)
        LogWorkerEvent('отказ создания нового воркера, превышение лимита');
    else
    {

        // Обратимся к странице worker.php и создадим нового воркера
        $sUrl = 'http://' . $sDomainName . '/worker.php';
        $ch = curl_init();

        // Скачивание
        $aCurlOptions = array (
            CURLOPT_HEADER => false,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_TIMEOUT_MS => 300,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPGET => false,
            CURLOPT_POST => true,
            CURLOPT_NOBODY => false,
            CURLOPT_URL => $sUrl
        );
        curl_setopt_array($ch, $aCurlOptions);

        $sRequestAnswer = curl_exec($ch);
        $aRequestInfo = curl_getinfo($ch);

        if (DEBUG_MODE)
        {
            if ($aRequestInfo ['http_code'] != 200)
                $aAnswer ['problem'] = 'http answer code: ' . $aRequestInfo ['http_code'];
            $aAnswer ['request-answer'] = $sRequestAnswer;
            $aAnswer ['request-info'] = $aRequestInfo;
        }
    }
}
elseif ($sTask == 'delworkers')
{
    // Очистим логи событий из Redis
    $oRedis->del('log:events');
    $oRedis->del('log:messages');

    // Удалим хэши воркеров
    if ($aWorkerIds = $oRedis->smembers('worker:ids') )
    {
        array_walk($aWorkerIds, function(&$sValue) { $sValue = 'worker:' . $sValue; } );
        $oRedis->del($aWorkerIds);
    }

    // Удалим id всех воркеров
    $oRedis->del('worker:ids');

    // Удалим id всех обработчиков
    $oRedis->del('handler:ids');

    // Удалим max id для генерирования новых воркеров
    $oRedis->del('worker:maxid');

    // Удалим id генератора
    $oRedis->del('generator:id');

    // Удалим время пинга генератора
    $oRedis->del('generator:pingtime');

    // Удалим количество обработанных сообщений
    $oRedis->del('messages:successcount');

    // Удалим количество ошибочных сообщений
    $oRedis->del('messages:errorcount');

    // Удалим пересылаемое сообщение
    $oRedis->del('message');

    // Очистка от возможного мусора в Redis
    if ($aGarbageKeys = $oRedis->keys('*') )
    {
        if (in_array('log:events', $aGarbageKeys) )
            unset($aGarbageKeys [array_search('log:events', $aGarbageKeys)]);

        if ($aGarbageKeys)
        {
            LogWorkerEvent('найден мусор в Redis, очищаем ключи: ' . implode(', ', $aGarbageKeys) );
            $oRedis->del($aGarbageKeys);
        }
    }

    $aAnswer ['workers'] = array();
    $aAnswer ['events'] = array();
    $aAnswer ['messages'] = array();
    $aAnswer ['msgsuccesscount'] = 0;
    $aAnswer ['msgerrorcount'] = 0;
}
elseif ($sTask == 'deloneworker' && $nWorkerId > 0)
{
    KillWorker($nWorkerId, false);
}

// Кто сейчас генератор? Добавим в json для отчётности...
$aAnswer ['generator:id'] = $oRedis->get('generator:id');

// Возврат количеств уже показанных строк логов
$aAnswer ['eventscount'] = $nLoadedEventsCount;
$aAnswer ['messagescount'] = $nLoadedMessagesCount;

// Оптимизация, незачем считывать список id воркеров, если мы его только что очистили
if ($sTask != 'delworkers')
{
    // Считываем из Redic список id воркеров
    $aAnswer ['workers'] = $oRedis->smembers('worker:ids');

    // Считываем из Redic лог событий
    $aAnswer ['events'] = $oRedis->lrange('log:events', $nLoadedEventsCount, -1);

    // Считываем из Redic лог сообщений
    $aAnswer ['messages'] = $oRedis->lrange('log:messages', $nLoadedMessagesCount, -1);

    // Считываем состояния воркеров
    if ($aAnswer ['workers'])
        foreach ($aAnswer ['workers'] as $nOneId)
            $aAnswer ['workersstate'] [$nOneId] = $oRedis->hget('worker:' . $nOneId, 'state');

    // Считываем количество обработанных сообщений
    $aAnswer ['msgsuccesscount'] = intval($oRedis->get('messages:successcount') );

    // Считываем количество ошибочных сообщений
    $aAnswer ['msgerrorcount'] = intval($oRedis->get('messages:errorcount') );
}

// А теперь отправляем информацию браузеру

// Лог растёт, да и без этого логично сжимать ответ сервера
$sJsonContent = json_encode($aAnswer);
$nJsonLength = strlen($sJsonContent);
if ( ($nJsonLength > 1024) && ($sEncoding = $_SERVER ['HTTP_ACCEPT_ENCODING']) && extension_loaded('zlib') )
{
    $aEncoding = explode(',', str_replace(' ', '', $sEncoding) );
    $sEncoding = '';

    if (in_array('gzip', $aEncoding) )
        $sEncoding = 'gzip';
    elseif (in_array('x-gzip', $aEncoding) )
        $sEncoding = 'x-gzip';

    if ($sEncoding)
    {
        $sJsonContent = gzencode($sJsonContent, 3);
        $nJsonLength = strlen($sJsonContent);

        header('Content-Encoding: ' . $sEncoding);
        header('Vary: Accept-Encoding');

        header('Content-Length: ' . $nJsonLength);
        echo $sJsonContent;
    }
    else
        echo $sJsonContent;
}
else
    echo $sJsonContent;
?>