<?php
namespace GoogleSpreadsheet;


class API {

	const HTTP_CODE_OK = 200;
	const HTTP_CODE_CREATED = 201;

	const CURL_BUFFER_SIZE = 16384;

	const CONTENT_TYPE_ATOMXML = 'application/atom+xml';
	const XMLNS_ATOM = 'http://www.w3.org/2005/Atom';
	const XMLNS_GOOGLE_SPREADSHEET = 'http://schemas.google.com/spreadsheets/2006';
	const API_BASE_URL = 'https://spreadsheets.google.com/feeds';

	// note: not using this HTTP header in requests - causes issue with getWorksheetCellList() method (cell versions not returned in XML)
	const API_VERSION_HTTP_HEADER = 'GData-Version: 3.0';

	private $RANGE_CRITERIA_MAP_COLLECTION = [
		'columnEnd' => 'max-col',
		'columnStart' => 'min-col',
		'returnEmpty' => 'return-empty',
		'rowEnd' => 'max-row',
		'rowStart' => 'min-row'
	];

	private $OAuth2GoogleAPI;


	public function __construct(\OAuth2\GoogleAPI $OAuth2GoogleAPI) {

		$this->OAuth2GoogleAPI = $OAuth2GoogleAPI;
	}

	public function getSpreadsheetList() {

		// init XML parser
		$parser = new API\Parser\SimpleEntry('/\/(?P<index>[a-zA-Z0-9-_]+)$/');
		$hasResponseData = false;

		// make request
		list($responseHTTPCode,$responseBody) = $this->OAuth2Request(
			self::API_BASE_URL . '/spreadsheets/private/full',
			null,
			function($data) use ($parser,&$hasResponseData) {

				$parser->process($data);
				$hasResponseData = true;
			}
		);

		// end of XML parse
		$parser->close();

		// HTTP code always seems to be 200 - so check for empty response body when in error
		if (!$hasResponseData) {
			throw new \Exception('Unable to retrieve spreadsheet listing');
		}

		return $parser->getList();
	}

	public function getWorksheetList($spreadsheetKey) {

		// init XML parser
		$parser = new API\Parser\SimpleEntry(
			'/\/(?P<index>[a-z0-9]+)$/',[
				'FEED/ENTRY/GS:COLCOUNT' => 'columnCount',
				'FEED/ENTRY/GS:ROWCOUNT' => 'rowCount'
			]
		);

		// make request
		list($responseHTTPCode,$responseBody) = $this->OAuth2Request(
			sprintf(
				'%s/worksheets/%s/private/full',
				self::API_BASE_URL,
				$spreadsheetKey
			),
			null,
			function($data) use ($parser) { $parser->process($data); }
		);

		// end of XML parse
		$parser->close();

		$this->checkAPIResponseError(
			$responseHTTPCode,$responseBody,
			'Unable to retrieve worksheet listing'
		);

		return $parser->getList();
	}

	public function getWorksheetDataList($spreadsheetKey,$worksheetID) {

		// supporting code for XML parse
		$worksheetHeaderList = [];
		$worksheetDataList = [];
		$dataItem = [];
		$addDataItem = function(array $dataItem) use (&$worksheetHeaderList,&$worksheetDataList) {

			if ($dataItem) {
				// add headers found to complete header list
				foreach ($dataItem as $headerName => $void) {
					$worksheetHeaderList[$headerName] = true;
				}

				// add list item to collection
				$worksheetDataList[] = $dataItem;
			}
		};

		// init XML parser
		$parser = new API\Parser(
			function($name,$elementPath) use ($addDataItem,&$dataItem) {

				if ($elementPath == 'FEED/ENTRY') {
					// store last data row and start new row
					$addDataItem($dataItem);
					$dataItem = [];
				}
			},
			function($elementPath,$data) use (&$dataItem) {

				// looking for a header element type
				if (preg_match('/^FEED\/ENTRY\/GSX:(?P<name>[^\/]+)$/',$elementPath,$match)) {
					$dataItem[strtolower($match['name'])] = trim($data);
				}
			}
		);

		// make request
		list($responseHTTPCode,$responseBody) = $this->OAuth2Request(
			sprintf(
				'%s/list/%s/%s/private/full',
				self::API_BASE_URL,
				$spreadsheetKey,
				$worksheetID
			),
			null,
			function($data) use ($parser) { $parser->process($data); }
		);

		// end of XML parse - add final parsed data row
		$parser->close();
		$addDataItem($dataItem);

		$this->checkAPIResponseError(
			$responseHTTPCode,$responseBody,
			'Unable to retrieve worksheet data listing'
		);

		// return header and data lists
		return [
			'headerList' => array_keys($worksheetHeaderList),
			'dataList' => $worksheetDataList
		];
	}

	public function getWorksheetCellList($spreadsheetKey,$worksheetID,array $cellCriteriaList = []) {

		// build cell fetch range criteria for URL if given
		$cellRangeCriteriaQuerystringList = [];
		if ($cellCriteriaList) {
			// ensure all given keys are valid
			if ($invalidCriteriaList = array_diff(
				array_keys($cellCriteriaList),
				array_keys($this->RANGE_CRITERIA_MAP_COLLECTION)
			)) {
				// invalid keys found
				throw new \Exception('Invalid cell range criteria key(s) [' . implode(',',$invalidCriteriaList) . ']');
			}

			// all valid, build querystring
			foreach ($this->RANGE_CRITERIA_MAP_COLLECTION as $key => $mapTo) {
				if (isset($cellCriteriaList[$key])) {
					$value = $cellCriteriaList[$key];
					$cellRangeCriteriaQuerystringList[] = ($key == 'returnEmpty')
						? sprintf('%s=%s',$mapTo,($value) ? 'true' : 'false')
						: sprintf('%s=%d',$mapTo,$value);
				}
			}
		}

		// supporting code for XML parse
		$worksheetCellList = [];
		$cellItemData = [];
		$addCellItem = function(array $cellItemData) use (&$worksheetCellList) {

			if (isset(
				$cellItemData['ref'],
				$cellItemData['value'],
				$cellItemData['URL']
			)) {
				// add cell item instance to list
				$cellReference = strtoupper($cellItemData['ref']);

				$worksheetCellList[$cellReference] = new CellItem(
					$cellItemData['URL'],
					$cellReference,
					$cellItemData['value']
				);
			}
		};

		// init XML parser
		$parser = new API\Parser(
			function($name,$elementPath,array $attribList) use ($addCellItem,&$cellItemData) {

				switch ($elementPath) {
					case 'FEED/ENTRY':
						// store last data row and start new row
						$addCellItem($cellItemData);
						$cellItemData = [];
						break;

					case 'FEED/ENTRY/LINK':
						if (
							(isset($attribList['REL'],$attribList['HREF'])) &&
							($attribList['REL'] == 'edit')
						) {
							// store versioned cell url
							$cellItemData['URL'] = $attribList['HREF'];
						}

						break;
				}
			},
			function($elementPath,$data) use (&$cellItemData) {

				switch ($elementPath) {
					case 'FEED/ENTRY/TITLE':
						$cellItemData['ref'] = $data; // cell reference (e.g. 'B1')
						break;

					case 'FEED/ENTRY/CONTENT':
						$cellItemData['value'] = $data; // cell value
						break;
				}
			}
		);

		// make request
		list($responseHTTPCode,$responseBody) = $this->OAuth2Request(
			sprintf(
				'%s/cells/%s/%s/private/full%s',
				self::API_BASE_URL,
				$spreadsheetKey,
				$worksheetID,
				($cellRangeCriteriaQuerystringList)
					? '?' . implode('&',$cellRangeCriteriaQuerystringList)
					: ''
			),
			null,
			function($data) use ($parser) { $parser->process($data); }
		);

		// end of XML parse - add final cell item
		$parser->close();
		$addCellItem($cellItemData);

		$this->checkAPIResponseError(
			$responseHTTPCode,$responseBody,
			'Unable to retrieve worksheet cell listing'
		);

		// return cell list
		return $worksheetCellList;
	}
	
	public function addListRow($spreadsheetKey, $worksheetID, $rowList) {
		$buffer = "<entry xmlns='http://www.w3.org/2005/Atom' " .
					"xmlns:gsx='http://schemas.google.com/spreadsheets/2006/extended'>\n";
		foreach ($rowList as $header => $value)
			$buffer .= sprintf("\t<gsx:%s>%s</gsx:%s>\n", $header, $value, $header);
		$buffer .= "</entry>\n";

		list($responseHTTPCode,$responseBody) = $this->OAuth2Request(
			sprintf('%s/list/%s/%s/private/full',
					self::API_BASE_URL,
					$spreadsheetKey,
					$worksheetID
			),
			function($bytesReadMax) use (&$buffer) { 
				$ret = substr($buffer,0,$bytesReadMax);
				$buffer = substr($buffer,$bytesReadMax);
				return $ret;
				},
			null
		);
		return true;
	}

	public function updateWorksheetCellList($spreadsheetKey,$worksheetID,array $worksheetCellList) {

		// scan cell list - at least one cell must be in 'dirty' state
		$hasDirty = false;
		foreach ($worksheetCellList as $cellItem) {
			if ($cellItem->isDirty()) {
				$hasDirty = true;
				break;
			}
		}

		if (!$hasDirty) {
			// no work to do
			return false;
		}

		// make request
		$cellIDIndex = -1;
		$excessBuffer = false;
		$finalCellSent = false;

		list($responseHTTPCode,$responseBody) = $this->OAuth2Request(
			sprintf(
				'%s/cells/%s/%s/private/full/batch',
				self::API_BASE_URL,
				$spreadsheetKey,
				$worksheetID
			),
			function($bytesWriteMax)
				use (
					$spreadsheetKey,$worksheetID,
					&$worksheetCellList,&$cellIDIndex,&$excessBuffer,&$finalCellSent
				) {

				if ($finalCellSent) {
					// end of data
					return '';
				}

				if ($excessBuffer !== false) {
					// send more buffer from previous run
					list($writeBuffer,$excessBuffer) = $this->splitBuffer($bytesWriteMax,$excessBuffer);
					return $writeBuffer;
				}

				if ($cellIDIndex < 0) {
					// emit XML header
					$cellIDIndex = 0;

					return sprintf(
						'<feed xmlns="%s" ' .
							'xmlns:batch="http://schemas.google.com/gdata/batch" ' .
							'xmlns:gs="%s">' .
						'<id>%s/cells/%s/%s/private/full</id>',
						self::XMLNS_ATOM,
						self::XMLNS_GOOGLE_SPREADSHEET,
						self::API_BASE_URL,
						$spreadsheetKey,$worksheetID
					);
				}

				// find next cell update to send
				$cellItem = false;
				while ($worksheetCellList) {
					$cellItem = array_shift($worksheetCellList);
					if ($cellItem->isDirty()) {
						// found cell to be updated
						break;
					}

					$cellItem = false;
				}

				if ($cellItem === false) {
					// no more cells
					$finalCellSent = true;
					return '</feed>';
				}

				$cellIDIndex++;
				list($writeBuffer,$excessBuffer) = $this->splitBuffer(
					$bytesWriteMax,
					$this->updateWorksheetCellListBuildBatchUpdateEntry(
						$spreadsheetKey,$worksheetID,
						$cellIDIndex,$cellItem
					)
				);

				// send write buffer
				return $writeBuffer;
			}
		);

		$this->checkAPIResponseError(
			$responseHTTPCode,$responseBody,
			'Unable to update worksheet cell(s)'
		);

		// all done
		return true;
	}

	public function addWorksheetDataRow($spreadsheetKey,$worksheetID,array $rowDataList) {

		$rowHeaderNameList = array_keys($rowDataList);
		$rowDataIndex = -1;
		$excessBuffer = false;
		$finalRowDataSent = false;

		list($responseHTTPCode,$responseBody) = $this->OAuth2Request(
			sprintf(
				'%s/list/%s/%s/private/full',
				self::API_BASE_URL,
				$spreadsheetKey,
				$worksheetID
			),
			function($bytesWriteMax)
				use (
					$spreadsheetKey,$worksheetID,
					$rowDataList,$rowHeaderNameList,
					&$rowDataIndex,&$excessBuffer,&$finalRowDataSent
				) {

				if ($finalRowDataSent) {
					// end of data
					return '';
				}

				if ($excessBuffer !== false) {
					// send more buffer from previous run
					list($writeBuffer,$excessBuffer) = $this->splitBuffer($bytesWriteMax,$excessBuffer);
					return $writeBuffer;
				}

				if ($rowDataIndex < 0) {
					// emit XML header
					$rowDataIndex = 0;

					return sprintf(
						'<entry xmlns="%s" xmlns:gsx="%s/extended">',
						self::XMLNS_ATOM,
						self::XMLNS_GOOGLE_SPREADSHEET
					);
				}

				if ($rowDataIndex >= count($rowHeaderNameList)) {
					// no more row column data
					$finalRowDataSent = true;
					return '</entry>';
				}

				$headerName = $rowHeaderNameList[$rowDataIndex];
				list($writeBuffer,$excessBuffer) = $this->splitBuffer(
					$bytesWriteMax,
					sprintf(
						'<gsx:%1$s>%2$s</gsx:%1$s>',
						$headerName,
						htmlspecialchars($rowDataList[$headerName])
					)
				);

				$rowDataIndex++;

				// send write buffer
				return $writeBuffer;
			}
		);

		$this->checkAPIResponseError(
			$responseHTTPCode,$responseBody,
			'Unable to add worksheet data row'
		);
	}

	private function updateWorksheetCellListBuildBatchUpdateEntry(
		$spreadsheetKey,$worksheetID,
		$cellBatchID,CellItem $cellItem
	) {

		$cellBaseURL = sprintf(
			'%s/cells/%s/%s/private/full/R%dC%d',
			self::API_BASE_URL,
			$spreadsheetKey,$worksheetID,
			$cellItem->getRow(),
			$cellItem->getColumn()
		);

		return sprintf(
			'<entry>' .
				'<batch:id>batchItem%d</batch:id>' .
				'<batch:operation type="update" />' .
				'<id>%s</id>' .
				'<link rel="edit" type="%s" href="%s/%s" />' .
				'<gs:cell row="%d" col="%d" inputValue="%s" />' .
			'</entry>',
			$cellBatchID,
			$cellBaseURL,
			self::CONTENT_TYPE_ATOMXML,
			$cellBaseURL,$cellItem->getVersion(),
			$cellItem->getRow(),$cellItem->getColumn(),
			htmlspecialchars($cellItem->getValue())
		);
	}

	private function OAuth2Request(
		$URL,
		callable $writeHandler = null,
		callable $readHandler = null
	) {

		$responseHTTPCode = false;
		$responseBody = '';

		// build option list
		$optionList = [
			CURLOPT_BUFFERSIZE => self::CURL_BUFFER_SIZE,
			CURLOPT_HEADER => false,
			CURLOPT_HTTPHEADER => [
				'Accept: ',
				'Expect: ', // added by CURLOPT_READFUNCTION

				// Google OAuth2 credentials
				implode(': ',$this->OAuth2GoogleAPI->getAuthHTTPHeader())
			],
			CURLOPT_RETURNTRANSFER => ($readHandler === null), // only return response from curl_exec() directly if no $readHandler given
			CURLOPT_URL => $URL
		];

		// add optional write/read data handlers
		if ($writeHandler !== null) {
			// POST data with XML content type if using a write handler
			$optionList += [
				CURLOPT_CUSTOMREQUEST => 'POST',
				CURLOPT_PUT => true, // required to enable CURLOPT_READFUNCTION
				CURLOPT_READFUNCTION =>
					// don't need curl instance/stream resource - so proxy handler in closure to remove
					function($curlConn,$stream,$bytesWriteMax) use ($writeHandler) {
						return $writeHandler($bytesWriteMax);
					}
			];

			$optionList[CURLOPT_HTTPHEADER][] = 'Content-Type: ' . self::CONTENT_TYPE_ATOMXML;
		}

		if ($readHandler !== null) {
			$optionList[CURLOPT_WRITEFUNCTION] =
				// proxy so we can capture HTTP response code before using given write handler
				function($curlConn,$data) use ($readHandler,&$responseHTTPCode,&$responseBody) {

					// fetch HTTP response code if not known yet
					if ($responseHTTPCode === false) {
						$responseHTTPCode = curl_getinfo($curlConn,CURLINFO_HTTP_CODE);
					}

					if ($responseHTTPCode == self::HTTP_CODE_OK) {
						// call handler
						$readHandler($data);

					} else {
						// bad response - put all response data into $responseBody
						$responseBody .= $data;
					}

					// return the byte count/size processed back to curl
					return strlen($data);
				};
		}

		$curlConn = curl_init();
		curl_setopt_array($curlConn,$optionList);

		// make request, close curl session
		// mute curl warnings that could fire from read/write handlers that throw exceptions
		set_error_handler(function() {},E_WARNING);
		$curlExecReturn = curl_exec($curlConn);
		restore_error_handler();

		if ($responseHTTPCode === false) {
			$responseHTTPCode = curl_getinfo($curlConn,CURLINFO_HTTP_CODE);
		}

		curl_close($curlConn);

		// return HTTP code and response body
		return [
			$responseHTTPCode,
			($readHandler === null) ? $curlExecReturn : $responseBody
		];
	}

	private function splitBuffer($bytesWriteMax,$buffer) {

		if (strlen($buffer) > $bytesWriteMax) {
			// split buffer at max write bytes and remainder
			return [
				substr($buffer,0,$bytesWriteMax),
				substr($buffer,$bytesWriteMax)
			];
		}

		// can send the full buffer at once
		return [$buffer,false];
	}

	private function checkAPIResponseError($HTTPCode,$body,$errorMessage) {

		if (
			($HTTPCode != self::HTTP_CODE_OK) &&
			($HTTPCode != self::HTTP_CODE_CREATED)
		) {
			// error with API call - throw error with returned message
			$body = trim(htmlspecialchars_decode($body,ENT_QUOTES));

			throw new \Exception(
				$errorMessage .
				(($body != '') ? ' - ' . $body : '')
			);
		}

		// all good
	}
}
