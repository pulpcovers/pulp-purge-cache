jQuery(function ($) {
	function ppcPurgeEverything(target) {
		$(target).prop('disabled', true);
		$.post(ppcAdmin.ajaxUrl, { action: 'ppc_purge_everything', nonce: ppcAdmin.nonce })
			.done(function (response) {
				alert(response && response.data ? response.data.message : 'Purge request completed.');
			})
			.fail(function () {
				alert('Purge request failed.');
			})
			.always(function () {
				$(target).prop('disabled', false);
			});
	}

	$('#wp-admin-bar-ppc-purge-everything a, #ppc-purge-everything').on('click', function (e) {
		e.preventDefault();
		ppcPurgeEverything(this);
	});
});
