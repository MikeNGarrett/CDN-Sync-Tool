jQuery(document).ready(function($) {
var queueTotal;
var qCount;
var queue;

function sync() {
	if(!queue || queue.length <= 0) {
		return;
	}
	var passedFile = queue.shift(); 
	var syncFileData = {
		action: 'cst_sync_file',
		cst_check: syncAjax.cst_check,
		file: passedFile,
		total: queueTotal,
		current: qCount
	};
	$.ajax({
		type: "post",
		url: syncAjax.ajax_url,
		data: syncFileData,
		success: function(response) {
			$(".cst-progress").append(response);
			qCount--;
			sync();
		}
	});
}


	var data = {
		action: 'cst_get_queue',
		cst_check: syncAjax.cst_check
	};
	// We can also pass the url value separately from ajaxurl for front end AJAX implementations
	$.ajax({
		type: "post",
		url: syncAjax.ajax_url,
		data: data,
		success: function(q) {
			queueTotal = q.length;
			qCount = q.length;
			if (q.length > 0) {
				queue = q;
				sync();
			} else { 
				// either no files or error
				$(".cst-progress").append(q);
			}

			// Upon completion, show the Return to Options Page button
			$(".cst-progress").ajaxStop(function() {
				$(this).append('<strong>All files synced.</strong>');
				$(".cst-progress-return").show();
			});
		},
		dataType: 'json'
	});

});
