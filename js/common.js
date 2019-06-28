jQuery(document).ready(function($){


	$("#addworkerbtn").click(function(){
		SendAjaxRequest("addworker");
	});

	$("#delworkersbtn").click(function(){
		SendAjaxRequest("delworkers");
	});

	$("#refreshbtn").click(function(){
		SendAjaxRequest("refresh");
	});


	// Обновим данные в списках
	RefreshData();

	// Автообновление данных
//	window.setInterval(RefreshData, 1000);
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
		data: {"task": sTask, "wid": nWorkerId},
		url: '/systemstate.php',
		type: "POST",
		success: AjaxHandler
	});
}

function AjaxHandler(data, textStatus, jqXHR)
{
	var nWorkersCount = data.workers.length;
	var nEventsCount = data.events.length;

	var jqMessagessCount = $("#messagescount");
	var jqWorkersCount = $("#workerscount");
	var jqWorkersList = $("#workerslist");
	var jqEventsList = $("#eventslist");

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

	// Обновление лога событий
	jqEventsList.html("");
	for (i = 0; i < nEventsCount; i ++)
		jqEventsList.append("<div>" + data.events [i] + "</div>");

	// Обновление количества обработанных сообщений
	jqMessagessCount.text(data.messagescount);
}
