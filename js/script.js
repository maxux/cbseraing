$(document).ready(function() {
	$('#login').focus();
	$('#nomreel').focus();
	$('.fancybox').fancybox();
	$('a.top').click(function() {
		$('html, body').animate({scrollTop: 0}, 'fast');
		return false;
	});
	$('.thumb a').tooltip();
	
	tinymce.init({
		selector: "textarea",
		plugins : "bbcode",
		theme_advanced_buttons1 : "bold,italic,underline,undo,redo,image,styleselect,removeformat,cleanup,code",
		theme_advanced_buttons2 : "",
		theme_advanced_buttons3 : "",
		theme_advanced_styles : "Code=codeStyle;Citer=quoteStyle",
		entity_encoding: "raw",
		add_unload_trigger: false,
		remove_linebreaks: false,
		inline_styles: false,
		convert_fonts_to_spans: false,
		theme_advanced_path: false,
	});
});
