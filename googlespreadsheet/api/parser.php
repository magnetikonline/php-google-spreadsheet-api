<?php
namespace GoogleSpreadsheet\API;


class Parser {

	private $XMLParser;
	private $parsedChunk = false;


	public function __construct($elementStartHandler,$dataHandler = null) {

		// create new XML parser
		$this->XMLParser = xml_parser_create();
		$nodePathList = [];
		$nodePathString = '';
		$elementData = '';

		// setup element start/end handlers
		xml_set_element_handler(
			$this->XMLParser,
			function($parser,$name,array $attribList)
				use ($elementStartHandler,&$nodePathList,&$nodePathString) {

				// update node path (level down)
				$nodePathList[] = $name;
				$nodePathString = implode('/',$nodePathList);

				if ($elementStartHandler !== null) {
					$elementStartHandler($name,$nodePathString,$attribList);
				}
			},
			function($parser,$name)
				use ($dataHandler,&$nodePathList,&$nodePathString,&$elementData) {

				// call the $dataHandler now with data stocked up in $elementData
				if ($dataHandler !== null) {
					$dataHandler($nodePathString,$elementData);
					$elementData = ''; // reset
				}

				// update node path (level up)
				array_pop($nodePathList);
				$nodePathString = implode('/',$nodePathList);
			}
		);

		// setup (optional) element data handler
		if ($dataHandler !== null) {
			xml_set_character_data_handler(
				$this->XMLParser,
				function($parser,$data) use (&$elementData) {

					// note: the function here will be called multiple times for a single node
					// if linefeeds are found - so we batch up all these calls into a single string
					$elementData .= $data;
				}
			);
		}
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
