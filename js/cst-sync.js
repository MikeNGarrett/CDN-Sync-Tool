jQuery(document).ready(function($) {

	var data = {
		action: 'cst_get_queue',
		cst_check: syncAjax.cst_check
	};
	// We can also pass the url value separately from ajaxurl for front end AJAX implementations
	$.ajax({
		type: "post",
		url: syncAjax.ajax_url,
		data: data,
		success: function(response) {
			var queue = response.slice(0,5);
			alert("Number of items: " + queue.length);

			if (queue.length > 0) {
				for (var x = 0; x < queue.length; x++) {
					alert("The ID for index " + x + " is: " + queue[x].id);
					var syncFileData = {
						action: 'cst_sync_file',
						cst_check: syncAjax.cst_check,
						file: queue[x]
					};
					$.ajax({
						type: "post",
						url: syncAjax.ajax_url,
						data: syncFileData
					});
				}
			}
			
			// var doSyncFile = function() {
			// 	var syncFileData = {
			// 		action: 'cst_sync_file',
			// 		cst_check: syncAjax.cst_check
			// 	};
			// 	$.post({
			// 		url: syncAjax.ajax_url,
			// 		data: syncFileData,
			// 		success: function(response) {
			// 			i++;
			// 			alert('The value of i is: ' + i);
			// 			//if (i > 3) {
			// 				return;
			// 			// }
			// 			// doSyncFile();
			// 		},
			// 		dataType: 'json'
			// 	});
			// };

			// doSyncFile();
		},
		dataType: 'json'
	});

});