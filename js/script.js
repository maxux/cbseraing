$(document).ready(function() {
	$('#login').focus();
	$('#nomreel').focus();
	$('.fancybox').fancybox();
	$('a.top').click(function() {
		$('html, body').animate({scrollTop: 0}, 'fast');
		return false;
	});
	$('.thumb a').tooltip();
});

function quote(id) {
	$('textarea#message').html('[cite]' + $('.raw-' + id).html() + '[/cite]');
	$('html, body').animate({scrollTop: $('textarea#message').offset().top}, 'fast');
	return false;
}
