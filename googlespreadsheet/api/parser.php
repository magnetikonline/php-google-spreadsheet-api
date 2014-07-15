<?php
namespace GoogleSpreadsheet\API;


class Parser {

	private $XMLParser;
	private $parsedChunk = false;


	public function __construct($elementStartHandler,$dataHandler) {

		// create new XML parser
		$this->XMLParser = xml_parser_create();
		$nodePathList = [];
		$nodePath = '';
		$elementData = '';

		// setup element start/end handlers
		xml_set_element_handler(
			$this->XMLParser,
			function($parser,$name,array $attribList)
				use ($elementStartHandler,&$nodePathList,&$nodePath) {

				// update node path (level down)
				$nodePathList[] = $name;
				$nodePath = implode('/',$nodePathList);

				// call $elementStartHandler with open node details
				$elementStartHandler($name,$nodePath,$attribList);
			},
			function($parser,$name)
				use ($dataHandler,&$nodePathList,&$nodePath,&$elementData) {

				// call $dataHandler now with node data buffered in $elementData
				$dataHandler($nodePath,$elementData);
				$elementData = ''; // reset

				// update node path (level up)
				array_pop($nodePathList);
				$nodePath = implode('/',$nodePathList);
			}
		);

		// setup element data handler
		xml_set_character_data_handler(
			$this->XMLParser,
			function($parser,$data) use (&$elementData) {

				// note: the function here will be called multiple times for a single node
				// if linefeeds are found - so we batch up all these calls into a single string
				$elementData .= $data;
			}
		);
	}

	public function parseChunk($data) {

		$this->parseXML($data);
		$this->parsedChunk = true;
	}

	public function close() {

		// used to signify the final chunk of XML to be parsed
		if ($this->parsedChunk) $this->parseXML('',true);
		xml_parser_free($this->XMLParser);
	}

	private function parseXML($data,$finalChunk = false) {

		if (!xml_parse($this->XMLParser,$data,$finalChunk)) {
			// throw XML parse exception
			throw new \Exception(
				'XML parse error: ' .
				xml_error_string(xml_get_error_code($this->XMLParser))
			);
		}
	}
}
