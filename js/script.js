var settings = {
	notifications: {
		delay: 120000
	}
};

$(document).ready(function() {
	$('#login').focus();
	$('#nomreel').focus();
	$('.fancybox').fancybox();
	$('a.top').click(function() {
		$('html, body').animate({scrollTop: 0}, 'fast');
		return false;
	});
	$('.thumb a').tooltip();
	
	setTimeout(forum, settings.notifications.delay);
});

//
// quote someone on forum message
//
function quote(id) {
	$('textarea#message').html('[cite]' + $('.raw-' + id).html() + '[/cite]');
	$('html, body').animate({scrollTop: $('textarea#message').offset().top}, 'fast');
	return false;
}

//
// polling for checking if there is new messages on forum
//
function notifications(data) {
	console.log(data);
	
	if(data.status != 'success')
		return;
	
	if(data.unread > 0)
		$('a[href="/forum"]').parent().addClass('newmessage');
	
	setTimeout(forum, settings.notifications.delay);
}

function forum() {
	$.ajax({ url: "/ajax/notifications" }).done(notifications);
}
