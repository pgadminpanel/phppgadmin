<?php

namespace PhpPgAdmin\Html;

class XHtmlOption extends XHtmlElement {
	function __construct($text, $value = null) {
		parent::__construct(null);			
		$this->set_text($text);
	}
}

