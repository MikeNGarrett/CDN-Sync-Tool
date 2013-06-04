jQuery(document).ready(function($) {

	var data = {
		action: 'cst_get_queue',
		cst_check: syncAjax.cst_check
	};
	// We can also pass the url value separately from ajaxurl for front end AJAX implementations
	$.post(syncAjax.ajax_url, data, function(response) {
		alert('Response from server: ' + response);

		var response = $.parseJSON(response);

		var numFiles = response.length;
		alert('Length of response: ' + numFiles);
		var i = 1;
		alert('Before entering the variable doSyncFile, the value of i is: ' + i);

		var doSyncFile = function() {
			var syncFileData = {
				action: 'cst_sync_file',
				cst_check: syncAjax.cst_check
			};
			$.post(syncAjax.ajax_url, syncFileData, function(response) {
				i++;
				alert('The value of i is: ' + i);
				//if (i > 3) {
					return;
				// }
				// doSyncFile();
			});
		};

		doSyncFile();
	});

});