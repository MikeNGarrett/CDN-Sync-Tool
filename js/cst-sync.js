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
			var queue = response;
			alert("Number of items: " + queue.length);
			// for (var x = 0; x < response.length; x++) {
			for (var x = 0; x < 1; x++) {
				alert("The ID for index " + x + " is: " + queue[x].id);
				var syncFileData = {
					action: 'cst_sync_file',
					cst_check: syncAjax.cst_check,
					item: queue[x]
				};
				$.ajax({
					type: "post",
					url: syncAjax.ajax_url,
					data: syncFileData
				});
			}
			//alert(response.length);
			// alert('Before entering the variable doSyncFile, the value of i is: ' + i);

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