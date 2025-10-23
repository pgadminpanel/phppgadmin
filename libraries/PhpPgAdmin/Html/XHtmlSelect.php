<?php

namespace PhpPgAdmin\Html;

class XHtmlSelect extends XHtmlElement {
	var $_data;

	function __construct($name, $multiple = false, $size = null) {
		parent::__construct();					

		$this->set_attribute("name", $name);
		if ($multiple) $this->set_attribute("multiple","multiple");
		if ($size) $this->set_attribute("size",$size);
		
		
	}
	
	function set_data(&$data, $delim = ",") {
		switch (gettype($data)) {
			case "string":
				$this->_data = explode($delim, $data);
				break;
			case "array":
				$this->_data = $data;
				break;
				
			default:
				break;
		}
	}
	
	function fetch() {
		if (isset($this->_data) && $this->_data) {
			foreach ($this->_data as $value) {
                $this->add(new XHtmlOption($value));
            }
		}
		return parent::fetch();
	}
	
}



