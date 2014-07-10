<?php
namespace GoogleSpreadsheet\API\Parser;


class SimpleEntry extends \GoogleSpreadsheet\API\Parser {

	private $entryList = [];
	private $entryItem = [];
	private $indexRegexp;
	private $additionalNodeSaveList;


	public function __construct(
		$indexRegexp,
		array $additionalNodeSaveList = []
	) {

		// save simple entry regexp and additional nodes to save for an entry
		$this->indexRegexp = $indexRegexp;
		$this->additionalNodeSaveList = $additionalNodeSaveList;

		// init XML parser
		parent::__construct(
			function($name,$nodePath) {

				if ($nodePath == 'FEED/ENTRY') {
					// store last entry and start next
					$this->addItem($this->entryItem);
					$this->entryItem = [];
				}
			},
			function($nodePath,$data) {

				switch ($nodePath) {
					case 'FEED/ENTRY/ID':
						$this->entryItem['ID'] = $data;
						break;

					case 'FEED/ENTRY/UPDATED':
						$this->entryItem['updated'] = strtotime($data);
						break;

					case 'FEED/ENTRY/TITLE':
						$this->entryItem['name'] = $data;
						break;

					default:
						// additional nodes to save
						if (
							$this->additionalNodeSaveList &&
							(isset($this->additionalNodeSaveList[$nodePath]))
						) {
							// found one - add to stack
							$this->entryItem[$this->additionalNodeSaveList[$nodePath]] = $data;
						}
				}
			}
		);
	}

	public function getList() {

		// add final parsed entry and return list
		$this->addItem($this->entryItem);
		return $this->entryList;
	}

	private function addItem(array $entryItem) {

		if (isset(
			$entryItem['ID'],
			$entryItem['updated'],
			$entryItem['name']
		)) {
			// if additional node save critera - ensure they were found for entry
			$saveEntryOK = true;
			if ($this->additionalNodeSaveList) {
				foreach ($this->additionalNodeSaveList as $entryKey) {
					if (!isset($entryItem[$entryKey])) {
						// not found - skip entry
						$saveEntryOK = false;
						break;
					}
				}
			}

			// extract the entry index from the ID to use as array index
			if (
				$saveEntryOK &&
				preg_match($this->indexRegexp,$entryItem['ID'],$match)
			) {
				$this->entryList[$match['index']] = $entryItem;
			}
		}
	}
}
