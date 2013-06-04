jQuery(document).ready(function($) {

	var data = {
		action: 'cst_get_queue',
		cst_check: syncAjax.cst_check
	};
	// We can also pass the url value separately from ajaxurl for front end AJAX implementations
	$.post(syncAjax.ajax_url, data, function(response) {
		alert('Response from server: ' + response);
	});

});