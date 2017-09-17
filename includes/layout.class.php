<?php
class LightLayout {
	private $layout = 'default.layout.html';

	private $render = NULL;
	private $data   = array(
	        'header'       => '',
		'container'    => '',
		'title'        => 'CB Seraing',
		'preload_head' => '',
		'menu_logged'  => '',
		'menu'         => '',
		'submenu'      => '',
	);

	private $selectdays  = array();
	private $selectyears = array();
	private $breadcrumb  = array();

	private $errors = NULL;
	private $custom = array();
	private $pages  = array();

	function __construct() {
		$this->render = file_get_contents(__DIR__.'/../layout/'.$this->layout);
		$this->buildselect();
	}

	function set($name, $value) {
		$this->data[$name] = $value;
	}

	function container_append($data) {
		$this->data['container'] .= $data;
	}

	function preload_append($data) {
		$this->data['preload_head'] .= $data."\n\t";
	}

	function file($file) {
		$this->container_append(file_get_contents($file));
	}

	function error_append($str) {
		$this->errors .= '<div class="bg-danger error">'.$str.'</div>';
	}

	function custom_add($key, $value) {
		$this->custom['{{'.$key.'}}'] = $value;
	}

	function custom_append($key, $value) {
		if(!isset($this->custom['{{'.$key.'}}']))
			return $this->custom_add($key, $value);

		$this->custom['{{'.$key.'}}'] .= $value;
	}

	function breadcrumb_add($url, $text) {
		$this->breadcrumb[$url] = $text;
	}

	private function breadcrumb() {
		if(count($this->breadcrumb) == 0)
			return '';

		$data = array();

		foreach($this->breadcrumb as $url => $item) {
			if($url != null)
				$data[] = '<li><a href="'.$url.'">'.$item.'</a></li>';

			else $data[] = '<li class="active">'.$item.'</li>';
		}

		return implode("\n", $data);
	}

	function menu($menu, $active, $highlight) {
		$list = $this->items($menu, $active, $highlight);
		$this->custom_add('MENU_ITEMS', $list);
		$this->data['menu'] = file_get_contents('layout/menu.layout.html');
	}

	function submenu($file, $list) {
		$this->container_append('{{SUBMENU}}');
		$this->data['submenu'] = file_get_contents($file);
		$this->custom_add('CUSTOM_SUBMENU', $list);
	}

	function buildselect() {
		$data = array();

		for($i = 1; $i < 31; $i++)
			$this->selectdays[] = '<option value="'.$i.'">'.$i.'</option>';

		for($i = date('Y') - 10; $i > 1900; $i--)
			$this->selectyears[] = '<option value="'.$i.'">'.$i.'</option>';
	}

	function items($list, $active, $custom = array()) {
		$text = array();

		foreach($list as $url => $title) {
			$classes = array();

			if(isset($custom[$url]))
				$classes[] = $custom[$url];

			if($url == $active)
				$classes[] = 'active';

			$text[] = '<li class="'.implode(' ', $classes).'">'.
			          '<a href="'.$url.'">'.$title.'</a>'.
			          '</li>';
		}

		return implode("\n", $text);
	}

	function render() {
		$parts = array(
			'{{HEADER_TITLE}}'     => $this->data['header'],
			'{{PAGE_TITLE}}'       => $this->data['title'],
			'{{TITLE}}'            => $this->data['title'],
			'{{CONTAINER}}'        => $this->data['container'],
			'{{MENU_LOGGED}}'      => $this->data['menu_logged'],
			'{{PRELOAD_HEAD}}'     => $this->data['preload_head'],
			'{{MENU}}'             => $this->data['menu'],
		);

		$global = array(
			'{{SUBMENU}}'          => $this->data['submenu'],
			'{{BREADCRUMB}}'       => $this->breadcrumb(),
			'{{SELECT_YEARS}}'     => implode($this->selectyears),
			'{{SELECT_DAYS}}'      => implode($this->selectdays),
			'{{ERRORS}}'           => $this->errors,
			'{{PAGES}}'            => $this->pages(),
		);

		// first pass
		$this->render = strtr($this->render, $parts);

		// layout container
		$this->render = strtr($this->render, $global);

		// layout custom
		$this->render = strtr($this->render, $this->custom);

		return $this->render;
	}

	function parse_file_custom($file) {
		$data = file_get_contents($file);
		return strtr($data, $this->custom);
	}

	function parse($array, $text) {
		return strtr($text, $array);
	}

	//
	// pagination
	//
	function pages_add($page, $url, $active) {
		$this->pages[] = array('text' => $page, 'url' => $url, 'active' => $active);
	}

	function pages() {
		$output = '<ul class="pagination">';

		$temp = array();
		foreach($this->pages as $page)
			$temp[] = '<li'.(($page['active']) ? ' class="active"' : '').'>'.
			          '<a href="'.$page['url'].'">'.$page['text'].'</a>'.
			          '</li>';

		$output .= implode($temp);
		$output .= '</ul>';

		return $output;
	}
}
?>
