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
