/**
 * Alert window
 * 
 * @param {type} message
 * @param {type} callback
 * @returns {undefined}
 */
BootstrapDialog.alert = function(message, callback) {
    new BootstrapDialog({
	message: message,
	data: {
	    'callback': callback
	},
	closable: false,
	buttons: [{
		label: 'OK',
		action: function(dialog) {
		    typeof dialog.getData('callback') === 'function' && dialog.getData('callback')(true);
		    dialog.close();
		}
	    }]
    }).open();
};

/**
 * Confirm window
 * 
 * @param {type} message
 * @param {type} callback
 * @returns {undefined}
 */
BootstrapDialog.confirm = function(message, callback) {
    new BootstrapDialog({
	title: 'Confirmation',
	message: message,
	closable: false,
	data: {
	    'callback': callback
	},
	buttons: [{
		label: 'Cancel',
		action: function(dialog) {
		    typeof dialog.getData('callback') === 'function' && dialog.getData('callback')(false);
		    dialog.close();
		}
	    }, {
		label: 'OK',
		cssClass: 'btn-primary',
		action: function(dialog) {
		    typeof dialog.getData('callback') === 'function' && dialog.getData('callback')(true);
		    dialog.close();
		}
	    }]
    }).open();
};
