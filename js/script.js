var settings = {
	notifications: {
		delay: 120000
	}
};

$(document).ready(function() {
	$('#login').focus();
	$('#nomreel').focus();
	$('.fancybox').fancybox();
	$('.btn-tooltip').tooltip();
	$('a.top').click(function() {
		$('html, body').animate({scrollTop: 0}, 'fast');
		return false;
	});


	$('.thumb a').tooltip();

	$(".btn-newsubject").click(function(){
		$('html, body').animate({ scrollTop: $('#subjectadd').offset().top - 200 }, 'fast');
	});

	$(".btn-newpost").click(function(){
		$('html, body').animate({ scrollTop: $('#newpost').offset().top - 200 }, 'fast');
	});
	
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
	
	else $('a[href="/forum"]').parent().removeClass('newmessage');
	
	setTimeout(forum, settings.notifications.delay);
}

function forum() {
	$.ajax({ url: "/ajax/notifications" }).done(notifications);
}




