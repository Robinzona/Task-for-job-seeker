<!DOCTYPE HTML PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html lang="ru" xmlns="http://www.w3.org/1999/xhtml" >
<head>
    <title>Полигон для игр с Redis</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">

    <link rel="STYLESHEET" type="text/css" href="/css/jquery.css">
    <link rel="STYLESHEET" type="text/css" href="/css/jquery.formstyler.css">
    <link rel="STYLESHEET" type="text/css" href="/css/common.css">

    <script language="JavaScript" type="text/javascript" src="/js/jquery-1.8.1.min.js"></script>
    <script language="JavaScript" type="text/javascript" src="/js/jquery.formstyler.min.js"></script>
    <script language="JavaScript" type="text/javascript" src="/js/common.js"></script>
</head>

<body>
    <h2>Полигон для игр с Redis</h2>

    <div class="page-wrapper">

        <div class="workerslist-wrapper">
            <label>Воркеры (<span id="workerscount">0</span>):</label>
            <div id="workerslist"></div>
        </div>

        <div class="eventslist-wrapper">
            <div class="eventslistcaption-wrapper">
                <label>Лог событий воркеров:</label>
                <div class="autorefresh-wrapper">
                    <label for="autorefresh">Автообновление данных
                        <input id="autorefresh" type="checkbox" class="styler" checked>
                    </label>
                </div>
            </div>
            <div id="eventslist"></div>
        </div>

        <div class="messageslist-wrapper">
            <label>Лог сообщений:</label>
            <div id="messageslist"></div>
        </div>

        <div class="button-wrapper">
            <button id="addworkerbtn" class="styler">Создать воркера</button><button
                id="delworkersbtn" class="styler">Полная очистка</button><button
                id="refreshbtn" class="styler">Обновить данные</button>

                <span id="messagescount" title="Счётчик обработанных и ошибочных сообщений">
                    <span class="success">0</span> / <span class="error"span>0</span>
                </span>
        </div>

        <div class="notes">
            Частота отправки сообщений генератором: каждые <?php echo $nGeneratorDelay; ?> секунд<br>
            Максимальное количество потоков воркеров: не более <?php echo $nMaxWorkersCount; ?>-ти
        </div>

    </div>

</body>
</html>