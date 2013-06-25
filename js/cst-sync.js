jQuery(document).ready(function($) {
	var queueTotal, qCount, queue, time;

	// function upDB is called to update the CDN Sync Tool database upon completion
	function upDB(file,time) {
		$.ajax({
			type: "post",
			url: syncAjax.ajax_url,
			data: {action: 'cst_update_db', cst_check: syncAjax.cst_check, time: time, file: file}
		});
	}

	// function completeSync is called to update the front end upon successful completion of CDN sync
	function completeSync() {
		$(".status").html('Syncing complete!');
		$('.cst-progress').append('<strong>All files synced.</strong>');
		$(".cst-progress-return").show();
	}

	// function sync is called recursively to sync individual files to the CDN
	function sync() {
		if(!queue || queue.length <= 0) {
			completeSync();
			return;
		}
		var passedFile = queue.shift(); 
		var syncFileData = {
			action: 'cst_sync_file',
			cst_check: syncAjax.cst_check,
			file: passedFile,
			total: queueTotal
		};
		$.ajax({
			type: "post",
			url: syncAjax.ajax_url,
			data: syncFileData,
			success: function(response) {
				upDB(passedFile,time);
				$(".status").html('Syncing in progress - do not close this window. Syncing '+(qCount - 1)+' of '+queueTotal);
				$(".cst-progress").append(response);
				qCount--;
				sync();
			}
		});
	}

	// parameters to retrieve CDN queue
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
				var date = new Date();
				time = date.getTime();
				$(".cst-progress").before('<div class="status"></div>');
				queue = q;
				sync();
			} else { 
				// either no files or error
				$(".cst-progress").append(q);
				$('.cst-progress').append('<strong>No files needed to be synced.</strong>');
				$(".cst-progress-return").show();
			}
		},
		error: function(xhr, textStatus, errorThrown) {
			$('.cst-progress').append('<strong>There was an error in retrieving the list of files to sync.</strong><br />');
			$('.cst-progress').append('Text status: ' + textStatus + '<br />');
			$('.cst-progress').append('Error thrown: ' + errorThrown + '<br />');
			$(".cst-progress-return").show();
		},
		dataType: 'json'
	});

});
