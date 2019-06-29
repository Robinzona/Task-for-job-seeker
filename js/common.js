// Глобальные переменные, всегда нужны, проинициализируем их лишь единожды, ради оптимизации
var jqMessagessCount, jqWorkersCount, jqWorkersList, jqEventsList, jqMessagesList,
	jqAutorefreshCB, nAutorefreshIntervalId;

jQuery(document).ready(function(){

	jqMessagessCount = $("#messagescount");
	jqWorkersCount = $("#workerscount");
	jqWorkersList = $("#workerslist");
	jqEventsList = $("#eventslist");
	jqMessagesList = $("#messageslist");
	jqAutorefreshCB = $("#autorefresh");

	$("#addworkerbtn").click(function(){ SendAjaxRequest("addworker"); });

	$("#delworkersbtn").click(function(){ SendAjaxRequest("delworkers"); });

	$("#refreshbtn").click(function(){ SendAjaxRequest("refresh"); });

	// Обновим данные в списках
	RefreshData();

	// Автообновление данных
	nAutorefreshIntervalId = 0;
	if (jqAutorefreshCB.prop("checked"))
		nAutorefreshIntervalId = window.setInterval(RefreshData, 1000);

	jqAutorefreshCB.change(function(){
		var bAutorefresh = jqAutorefreshCB.prop("checked");

		if (bAutorefresh && !nAutorefreshIntervalId)
			nAutorefreshIntervalId = window.setInterval(RefreshData, 1000);
		else if (!bAutorefresh && nAutorefreshIntervalId)
		{
			window.clearInterval(nAutorefreshIntervalId);
			nAutorefreshIntervalId = 0;
		}
	});

});

function RefreshData()
{
	SendAjaxRequest("refresh");
}

function SendAjaxRequest(sTask, nWorkerId = null)
{
	jQuery.ajax({
		cache: false,
		dataType: "json",
		data: { "task": sTask, "wid": nWorkerId,
			"eventscount": jqEventsList.find(">div").length,
			"messagescount": jqMessagesList.find(">div").length },
		url: '/systemstate.php',
		type: "POST",
		success: AjaxHandler
	});
}

function AjaxHandler(data, textStatus, jqXHR)
{
	var nWorkersCount = data.workers.length;
	var nEventsCount = data.events.length;
	var nMessagesCount = data.messages.length;

	// Обновление id воркеров
	jqWorkersCount.text(nWorkersCount);
	jqWorkersList.html("");
	for (i = 0; i < nWorkersCount; i ++)
	{
		var nWorkerId = data.workers [i];
		var nWorkerState = data.workersstate [nWorkerId];
		var sWorkerState = "<span class=\"worker-state\">" +
				(nWorkerState == 1 ? "О" : "Г") + "</span>";
		var jqWorkerDel = $("<span wid=\"" + nWorkerId + "\" class=\"del-worker\" title=\"Удалить воркера\">х</span>").click(function(){
			SendAjaxRequest("deloneworker", $(this).attr("wid") );
		});
		var sWorkerLine = "<div title=\"" + (nWorkerState == 1 ? "Обработчик\"" : "Генератор\" class=\"generator\"") + ">" +
				sWorkerState + nWorkerId + "</div>";
		jqWorkersList.append($(sWorkerLine).append(jqWorkerDel) );
	}

	// Обновление количества обработанных и ошибочных сообщений
	jqMessagessCount.find("span.success").text(parseInt(data.msgsuccesscount) )
		.end().find("span.error").text(parseInt(data.msgerrorcount) );

	if (data.task == "delworkers")
	{
		// Очистка лога событий
		jqEventsList.html("");
		// Очистка лога сообщений
		jqMessagesList.html("");
	}
	else
	{
		var nPos, bErrorMessage;
		var nLoadedEventsCount = jqEventsList.find(">div").length;
		var nLoadedMessagesCount = jqMessagesList.find(">div").length;

		// Обновление лога событий
		for (i = nLoadedEventsCount - data.eventscount; i < nEventsCount; i ++)
		{
			nPos = data.events [i].indexOf(" ");
			jqEventsList.prepend("<div><span class=\"time\">" +
					data.events [i].substr(0, nPos) + "</span>" +
					data.events [i].substr(nPos + 1) + "</div>");
		}

		// Обновление лога сообщений
		for (i = nLoadedMessagesCount - data.messagescount; i < nMessagesCount; i ++)
		{
			bErrorMessage = data.messages [i].indexOf("ошибка!") < 0 ? false : true;
			nPos = data.messages [i].indexOf(" ");
			jqMessagesList.prepend("<div class=\"" + (bErrorMessage ? "err" : "ok") + "\">" +
					"<span class=\"time\">" + data.messages [i].substr(0, nPos) + "</span>" +
					data.messages [i].substr(nPos + 1) + "</div>");
		}
	}
}
