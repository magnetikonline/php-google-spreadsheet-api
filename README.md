# Google Spreadsheets PHP API
PHP library allowing read/write access to existing Google Spreadsheets and their data. Uses the [version 3 API](https://developers.google.com/sheets/api/v3/), which is now on a deprecation path (as of February 2017) in favor of a version 4 API.

Since this API uses [OAuth2](https://oauth.net/2/) for client authentication a *very lite* (and somewhat incomplete) set of [classes for obtaining OAuth2 tokens](oauth2) is included.

- [Requires](#requires)
- [Methods](#methods)
	- [API()](#api)
	- [API()->getSpreadsheetList()](#api-getspreadsheetlist)
	- [API()->getWorksheetList($spreadsheetKey)](#api-getworksheetlistspreadsheetkey)
	- [API()->getWorksheetDataList($spreadsheetKey,$worksheetID)](#api-getworksheetdatalistspreadsheetkeyworksheetid)
	- [API()->getWorksheetCellList($spreadsheetKey,$worksheetID[,$cellCriteriaList])](#api-getworksheetcelllistspreadsheetkeyworksheetidcellcriterialist)
	- [API()->updateWorksheetCellList($spreadsheetKey,$worksheetID,$worksheetCellList)](#api-updateworksheetcelllistspreadsheetkeyworksheetidworksheetcelllist)
	- [API()->addWorksheetDataRow($spreadsheetKey,$worksheetID,$rowDataList)](#api-addworksheetdatarowspreadsheetkeyworksheetidrowdatalist)
- [Example](#example)
	- [Setup](#setup)
- [Known issues](#known-issues)
- [Reference](#reference)

## Requires
- PHP 5.4 (uses [anonymous functions](http://php.net/manual/en/functions.anonymous.php) extensively).
- [cURL](https://php.net/curl).
- Expat [XML Parser](http://docs.php.net/manual/en/book.xml.php).

## Methods

### API()
Constructor accepts an instance of `OAuth2\GoogleAPI()`, which handles OAuth2 token fetching/refreshing and generation of HTTP authorization headers used with all Google spreadsheet API calls.

The included [`example.php`](example.php) provides [usage](example.php#L25-L36) [examples](base.php#L12-L22).

### API()->getSpreadsheetList()
Returns a listing of available spreadsheets for the requesting client.

```php
$OAuth2GoogleAPI = new OAuth2\GoogleAPI(/* URLs and client identifiers */);
$OAuth2GoogleAPI->setTokenData(/* Token data */);
$OAuth2GoogleAPI->setTokenRefreshHandler(/* Token refresh handler callback */);
$spreadsheetAPI = new GoogleSpreadsheet\API($OAuth2GoogleAPI);

print_r(
	$spreadsheetAPI->getSpreadsheetList()
);

/*
[SPREADSHEET_KEY] => Array
(
	[ID] => 'https://spreadsheets.google.com/feeds/spreadsheets/private/full/...'
	[updated] => UNIX_TIMESTAMP
	[name] => 'Spreadsheet name'
)
*/
```

[API reference](https://developers.google.com/sheets/api/v3/worksheets#retrieve_a_list_of_spreadsheets)

### API()->getWorksheetList($spreadsheetKey)
Returns a listing of defined worksheets for a given `$spreadsheetKey`.

```php
$OAuth2GoogleAPI = new OAuth2\GoogleAPI(/* URLs and client identifiers */);
$OAuth2GoogleAPI->setTokenData(/* Token data */);
$OAuth2GoogleAPI->setTokenRefreshHandler(/* Token refresh handler callback */);
$spreadsheetAPI = new GoogleSpreadsheet\API($OAuth2GoogleAPI);

print_r(
	$spreadsheetAPI->getWorksheetList('SPREADSHEET_KEY')
);

/*
[WORKSHEET_ID] => Array
(
	[ID] => 'https://spreadsheets.google.com/feeds/...'
	[updated] => UNIX_TIMESTAMP
	[name] => 'Worksheet name'
	[columnCount] => TOTAL_COLUMNS
	[rowCount] => TOTAL_ROWS
)
*/
```

[API reference](https://developers.google.com/sheets/api/v3/worksheets#retrieve_information_about_worksheets)

### API()->getWorksheetDataList($spreadsheetKey,$worksheetID)
Returns a read only 'list based feed' of data for a given `$spreadsheetKey` and `$worksheetID`.

List based feeds have a specific format as defined by Google - see the [API reference](https://developers.google.com/sheets/api/v3/data#retrieve_a_list-based_feed) for details. Data is returned as an array with two keys - defined headers and the data body.

```php
$OAuth2GoogleAPI = new OAuth2\GoogleAPI(/* URLs and client identifiers */);
$OAuth2GoogleAPI->setTokenData(/* Token data */);
$OAuth2GoogleAPI->setTokenRefreshHandler(/* Token refresh handler callback */);
$spreadsheetAPI = new GoogleSpreadsheet\API($OAuth2GoogleAPI);

print_r(
	$spreadsheetAPI->getWorksheetDataList('SPREADSHEET_KEY','WORKSHEET_ID')
);

/*
Array
(
	[headerList] => Array
	(
		[0] => 'Header name #1'
		[1] => 'Header name #2'
		[x] => 'Header name #x'
	)

	[dataList] => Array
	(
		[0] => Array
		(
			['Header name #1'] => VALUE
			['Header name #2'] => VALUE
			['Header name #x'] => VALUE
		)

		[1]...
	)
)
*/
```

[API reference](https://developers.google.com/sheets/api/v3/data#retrieve_a_list-based_feed)

### API()->getWorksheetCellList($spreadsheetKey,$worksheetID[,$cellCriteriaList])
Returns a listing of individual worksheet cells for an entire sheet, or a specific range (via `$cellCriteriaList`) for a given `$spreadsheetKey` and `$worksheetID`.

- Cells returned as an array of [`GoogleSpreadsheet\CellItem()`](googlespreadsheet/cellitem.php) instances, indexed by cell reference (e.g. `B1`).
- Cell instances can be modified and then passed into [`API()->updateWorksheetCellList()`](#api-updateworksheetcelllist) to update source spreadsheet.
- An optional `$cellCriteriaList` boolean option of `returnEmpty` determines if method will return empty cell items.

```php
$OAuth2GoogleAPI = new OAuth2\GoogleAPI(/* URLs and client identifiers */);
$OAuth2GoogleAPI->setTokenData(/* Token data */);
$OAuth2GoogleAPI->setTokenRefreshHandler(/* Token refresh handler callback */);
$spreadsheetAPI = new GoogleSpreadsheet\API($OAuth2GoogleAPI);

// fetch first 20 rows from third column (C) to the end of the sheet
// if $cellCriteria not passed then *all* cells for the spreadsheet will be returned
$cellCriteria = [
	'returnEmpty' = true,
	'columnStart' => 3
	'rowStart' => 1
	'rowEnd' => 20
];

print_r(
	$spreadsheetAPI->getWorksheetCellList(
		'SPREADSHEET_KEY','WORKSHEET_ID',
		$cellCriteria
	)
);

/*
Array
(
	[CELL_REFERENCE] => GoogleSpreadsheet\CellItem Object
	(
		getRow()
		getColumn()
		getReference()
		getValue()
		setValue()
		isDirty()
	)

	[CELL_REFERENCE]...
)
*/
```

[API reference](https://developers.google.com/sheets/api/v3/data#retrieve_a_cell-based_feed)

### API()->updateWorksheetCellList($spreadsheetKey,$worksheetID,$worksheetCellList)
Accepts an array of `GoogleSpreadsheet\CellItem()` instances as `$worksheetCellList` for a given `$spreadsheetKey` and `$worksheetID`, updating target spreadsheet where cell values have been modified from source via the [`GoogleSpreadsheet\CellItem()->setValue()`](googlespreadsheet/cellitem.php#L62-L65) method.

Given cell instances that _have not_ been modified are skipped (no work to do).

```php
$OAuth2GoogleAPI = new OAuth2\GoogleAPI(/* URLs and client identifiers */);
$OAuth2GoogleAPI->setTokenData(/* Token data */);
$OAuth2GoogleAPI->setTokenRefreshHandler(/* Token refresh handler callback */);
$spreadsheetAPI = new GoogleSpreadsheet\API($OAuth2GoogleAPI);

$cellList = $spreadsheetAPI->getWorksheetCellList('SPREADSHEET_KEY','WORKSHEET_ID');
$cellList['CELL_REFERENCE']->setValue('My updated value');

$spreadsheetAPI->updateWorksheetCellList(
	'SPREADSHEET_KEY','WORKSHEET_ID',
	$cellList
);
```

[API reference](https://developers.google.com/sheets/api/v3/data#update_multiple_cells_with_a_batch_request)

### API()->addWorksheetDataRow($spreadsheetKey,$worksheetID,$rowDataList)
Add a new data row to an existing worksheet, directly after the last row. The last row is considered the final containing any non-empty cells.

Accepts a single row for insert at the bottom as an array via `$rowDataList`, where each array key matches a row header.

```php
$OAuth2GoogleAPI = new OAuth2\GoogleAPI(/* URLs and client identifiers */);
$OAuth2GoogleAPI->setTokenData(/* Token data */);
$OAuth2GoogleAPI->setTokenRefreshHandler(/* Token refresh handler callback */);
$spreadsheetAPI = new GoogleSpreadsheet\API($OAuth2GoogleAPI);

$dataList = $spreadsheetAPI->getWorksheetDataList($spreadsheetKey,$worksheetID);
print_r($dataList);

/*
Array
(
	[headerList] => Array
		(
			[0] => firstname
			[1] => lastname
			[2] => jobtitle
			[3] => emailaddress
		)

	[dataList] => Array
		(
			... existing data ...
		)
)
*/

$spreadsheetAPI->addWorksheetDataRow($spreadsheetKey,$worksheetID,[
	'firstname' => 'Bob',
	'lastname' => 'Jones',
	'jobtitle' => 'UX developer',
	'emailaddress' => 'bob.jones@domain.com'
]);
```

[API reference](https://developers.google.com/sheets/api/v3/data#add_a_list_row)

## Example
The provided [`example.php`](example.php) CLI script will perform the following tasks:
- Fetch all available spreadsheets for the requesting client and display.
- For the first spreadsheet found, fetch all worksheets and display.
- Fetch a data listing of the first worksheet.
- Fetch a range of cells for the first worksheet.
- Finally, modify the content of the first cell fetched (commented out in example).

### Setup
- Create a new project at: https://console.developers.google.com/projectcreate.
- Generate set of OAuth2 tokens via `API Manager -> Credentials`:
	- Click `Create credentials` drop down.
	- Select `OAuth client ID` then `Web application`.
	- Enter friendly name for client ID.
	- Enter an `Authorized redirect URI` - this *does not* need to be a real URI for the example.
	- Note down both generated `client ID` and `client secret` values.
- Modify [`config.php`](config.php) entering `redirect` URI, `clientID` and `clientSecret` values generated above.
- Visit the [Allow Risky Access Permissions By Unreviewed Apps](https://groups.google.com/forum/#!forum/risky-access-by-unreviewed-apps) Google group and `Join group` using your Google account.
	- **Note:** For long term access it would be recommended to submit a "OAuth Developer Verification" request to Google.
- Execute [`buildrequesturl.php`](buildrequesturl.php) and visit generated URL in a browser.
- After accepting access terms and taken back to redirect URI, note down the `?code=` query string value (minus the trailing `#` character).
- Execute [`exchangecodefortokens.php`](exchangecodefortokens.php), providing `code` from the previous step. This step should be called within a short time window before `code` expires.
- Received OAuth2 token credentials will be saved to `./.tokendata`.
	- **Note:** In a production application this sensitive information should be saved in a secure form to datastore/database/etc.

Finally, run `example.php` to view the result.

**Note:** If OAuth2 token details stored in `./.tokendata` require a refresh (due to expiry), the function handler set by [`OAuth2\GoogleAPI->setTokenRefreshHandler()`](oauth2/googleapi.php#L36-L39) will be called to allow the re-save of updated token data back to persistent storage.

## Known issues
The Google spreadsheet API documents suggest requests can [specify the API version](https://developers.google.com/sheets/api/v3/authorize#specify_a_version). Attempts to do this cause the [cell based feed](https://developers.google.com/sheets/api/v3/data#retrieve_a_cell-based_feed) response to avoid providing the cell version slug in `<link rel="edit">` nodes - making it impossible to issue an update of cell values. So for now, I have left out sending the API version HTTP header.

## Reference
- OAuth2
	- https://tools.ietf.org/html/rfc6749
	- https://developers.google.com/accounts/docs/OAuth2WebServer
	- https://developers.google.com/oauthplayground/
- Google Spreadsheets API version 3.0
	- https://developers.google.com/sheets/api/v3/
