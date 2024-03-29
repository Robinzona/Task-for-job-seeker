# Выполнение тестового задания от работодателя
Текст задания размещён [внизу страницы](#текст-задания) 

## Как и что реализовано
Код размещён на моём домашнем компьютере, порты я пробросил, живое демо доступно по адресу [task.webforeverteam.com](http://task.webforeverteam.com/).
Код размещён также на GitHub в репозитории [Robinzona/Task-for-job-seeker](https://github.com/Robinzona/Task-for-job-seeker).

Первая версия в ветке [Messages-with-pub/sub](https://github.com/Robinzona/Task-for-job-seeker/tree/Messages-with-pub/sub), там я пытался реализовать передачу и приём сообщений между воркерами методом `publish/subscribe` `Redis`-а. Путь оказался тупиковым. 

Причины: 
* генератор может отвалиться от сети и живые обработчики должны периодически проверять, не пора ли им стать генератором;
* по условиям задачи, кроме обработчиков и генератора, не должно быть других потоков, которые мониторили бы умирание генератора и назначали нового среди обработчиков;
* когда поток обработчика входит в подписку - управление уходит в ожидание прихода сообщения, и возвращается в код потока только после прихода сообщения; 
* во всех найденных мною примерах прерывание подписки реализовано только извне, через отправку специализированного сообщения другим потоком-отправителем;
* если генератор умер и перестал слать сообщения - управление в поток обработчика так и не вернётся, некому подхватить роль умершего генератора;
* найти способ прерывать подписку по периоду времени, а не по приходу сообщения - не удалось, не помогли ни примеры из `predis`, ни исходники его классов, ни помощь Гугла.

Итог: это оказался тупиковый подход, для инициирования обработчиком проверки генератора на жизнеспособность должно придти спецсообщение от умершего генератора, который уже ничего никому не пошлёт. Поэтому от методики `publish/subscribe` пришлось отказаться. Потрачено времени на выяснение необъяснимого поведения `Redis`-а - гора и маленькая тележка. Единственный плюс этих изысканий - полученный опыт, в каких ситуациях `publish/subscribe` не подходит в принципе. Второй раз на такие грабли не наступлю.

Вторая версия в ветке [custom-messaging](https://github.com/Robinzona/Task-for-job-seeker/tree/custom-messaging).

### Нюансы второй версии:
* Отправка сообщения реализована путём сохранения нужной информации в хэше `message` `Redis`-а. 
* Приём сообщения организован путём проверки появления этого хэша обработчиками. 
* Сообщение забирает и обрабатывает первый обратившийся обработчик. 
* Защита от обработки одного сообщения несколькими обработчиками реализована через `watch`-инг этого хэша и транзакции.
* В условиях задачи не было необходимости прихранивать те сообщения, которые не были обработаны из-за отсутствия воркеров-обработчиков. Поэтому фонкционала буфера для необработанных сообщений я не делал, в системе может одномоментно передаваться только одно сообщение.

Оттестированный код со второй ветки заброшен на ветку [master](https://github.com/Robinzona/Task-for-job-seeker/tree/master), написан текст этого `README.md` и добавлен скриншот внешнего вида полигона для экспериментов.

### Список использованных при решении задания команд Redis:
`set`, `get`, `del`, `incr`, `exists`, `sadd`, `srem`, `smembers`, `sismember`, `hset`, `hget`, `hdel`, `rpush`, `lrange`, `keys`, `watch`, `unwatch`, `multi`, `exec`. 

### Описание логики реализации моего решения поставленной задачи
* Функционал отдельного потока для воркера реализован на `php`, вызовом файла `worker.php`.
* Полигон для тестирования и визуализации результатов работы реализован в файле `systemstate.php`. 
* В файле `_template/design.tpl` - вёрстка для внешнего вида полигона. CSS-cтили для него в файле `css/common.css`.
* В файле `predis-connect.php` - код коннекта к `Redis` через библиотеку `predis`, чтобы не дублировать в нескольких местах.
* В файле `settings.php` - константы и определения, также вынесены несколько функций для удобства.
* Для отправки управляющих команд к серверу используется POST-запросы на ajax, использована библиотека jQuery. Код JavaScript в файле `js/common.js`.
* Создание нового потока воркера сделано через curl-запрос к `worker.php`.
* Возможность имитировать потерю связи с воркером реализована через хранение в наборе `worker:ids` идентификаторов воркеров. При запуске воркера он получает уникальный идентификатор и добавляет его в набор. При необходимости прекратить выполнение кода потока воркера - в коде `systemstate.php` идентификатор этого воркера удаляется из `worker:ids`, код воркера при следующей итерации обнаруживает это и завершает работу. При реализации логики под поставленную задачу набор `worker:ids` не используется, он нужен только для управления жизненным циклом потока каждого воркера.
* Оба типа воркеров, и генератор, и обработчик, регулярно записывают в `Redis` текущее время, так остальные воркеры могут определить, потеряна связь с этим воркером или он ещё жив.
* Если время последнего отчёта генератора превышено - первый попавшийся обработчик пробует стать генератором. Защита от появления нескольких генераторов реализована через `watch`-инг и транзакции.
* Текущий живой генератор периодически проверяет времена отчётов обработчиков. Если у обработчика оно превышено - генератор считает его умершим и удаляет информацию о нём из `Redis`.
* Если генератору подошло время сгенерировать новое сообщение, но при этом живых обработчиков он не обнаружил - сообщение не создаётся, оно будет создано сразу, как появится первый живой обработчик.
* В полигоне ведется подсчёт успешно обработанных сообщений, количество хранится в переменной `messages:successcount`. Это количество показывается в полигоне в блоке справа снизу, первой цифрой.
* В полигоне ведется также подсчёт ошибочных выполнений (значение сообщения было больше 8), количество хранится в переменной `messages:errorcount`. Это количество показывается в полигоне в блоке справа снизу, второй цифрой. Хоть это и не требовалось по условиям задачи - сделано для удобства и контроля.
* В полигоне ведется логирование событий, происходящих с воркерами, данные добавляются в список `log:events`. Данные из него показываются в блоке "Лог событий воркеров". Это не требовалось по условиям задачи, но без этого нереально было отладить код многопоточного взаимодействия воркеров, да и не вижу другого способа продемонстрировать принимающей код стороне, что всё работает правильно и без багов.
* В полигоне ведется логирование обработки сообщений, причём не только ошибочных, но и успешных. Данные добавляются в список `log:messages`. Данные из него показываются в блоке "Лог сообщений".
* В полигоне в блоке справа показывается текущий список живых воркеров. Буква `О` или `Г` в начале строки воркера - его состояние, обработчик или генератор. Далее следует идентификатор воркера. И правее - красный крестик для возможности дать команду убить этого воркера.
* Создать новый поток с воркером можно через клик по кнопке "Создать воркера". При создании воркер по умолчанию устанавливается в состояние обработчика.
* По кнопке "Полная очистка" производится обнуление системы, данные очищаются, потокам воркеров даётся команда на завершение, все данные из `Redis` также удаляются. После этого система готова к добавлению новых воркеров и новым экспериментам.
* По умолчанию данные для внешнего вида полигона запрашиваются каждую секунду, так на экране появляются все изменения. Автообновление можно отключить по птичке сверху интерфейса. Тогда появляется смысл ручного обновления информации через нажатие кнопки "Обновить данные". Автообновление можно включить/выключить в любой момент.

### Внешний вид полигона
![Скриншот](http://task.webforeverteam.com/img/screenshort.png)

### Живое демо
[task.webforeverteam.com](http://task.webforeverteam.com/)

## Текст задания
> Необходимо реализовать распределенную систему, использующую для коммуникации между процессами только редис, в которой каждый воркер может быть в 1м из 2х состояний: Генератор / Обработчик
> 
> Генератор генерирует целочисленное значение от 0 до 10, раз в определенный конфигом промежуток времени
> 
> Обработчик получает переданное генератором число и обрабатывает его по следующим условиям
> * В случае, если число > 8 выполнение считается ошибочным, воркер добавляет ошибку в список, хранящийся в редисе
> * В противном случае - увеличивает на единицу счетчик обработанных сообщений, хранящийся в редис.
> 
> Система должна удовлетворять следующим условиям:
> 
> * В каждый момент времени должно быть активно не более одного генератора
> * В случае, если в системе нет генератора - случайный воркер прекращает быть обработчиком и становится генератором
> * Время отсутствия активного генератора в системе не должно превышать 10 секунд
> * При разработки алгоритма работы нужно учесть, что процессы считаются запущенными на разных компьютерах, и единственным доступным средством коммуникации является редис.
> * Также, сетевое соединение к редис считается в общем случае не стабильным, и система должна обрабатывать это, переключая один из живых процессов в режим генератора
> 
> Для разработки можно использовать любые utility библиотеки, но никаких оберток над редис, тоесть сам алгоритм работы должен быть реализован соискателем.
> 
> Язык реализации не важен
