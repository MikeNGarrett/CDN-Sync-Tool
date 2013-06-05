jQuery(document).ready(function($) {

	var data = {
		action: 'cst_get_queue',
		cst_check: syncAjax.cst_check
	};

function sync() {
	console.log("The ID for index " + x + " is: " + queue[x].id);
	var passedFile = queue[x]; // eventually this will be populated by pulling element from cookie-based queue
	var syncFileData = {
		action: 'cst_sync_file',
		cst_check: syncAjax.cst_check,
		file: passedFile,
		total: queue.length
	};
	$.ajax({
		type: "post",
		url: syncAjax.ajax_url,
		data: syncFileData,
		success: function(response) {
			$(".cst-progress").append(response.message);
			console.log(document.cookie.cst_queue);
			sync();
		}
	});
}

	// We can also pass the url value separately from ajaxurl for front end AJAX implementations
	$.ajax({
		type: "post",
		url: syncAjax.ajax_url,
		data: data,
		success: function(response) {
			var queue = response.slice(0,5);
			var date = new Date();
			date.setTime(date.getTime()+(10*24*60*60*1000));
			var expires = date.toGMTString();
			document.cookie =
  'cst_queue='+queue='; expires='+expires+'; path=/';
			console.log("Number of items: " + queue.length);

			if (queue.length > 0) {
			//				for (var x = 0; x < queue.length; x++) {
				console.log(queue);
				console.log(document.cookie.cst_queue);
				//sync(queue[queue.length - 1]);
			//				}

			}

			// Upon completion, show the Return to Options Page button
			$(".cst-progress").ajaxStop(function() {
				$(this).append('<strong>All files synced.</strong>');
				$(".cst-progress-return").show();
			});
			
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
			// 			console.log('The value of i is: ' + i);
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