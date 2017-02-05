<?php 

class XMLSorter
{
	public $pdo                   = NULL;
	public $tablesWithForeignKeys = [];
	public $verbosity             = 0;
	public $database              = NULL;
	public $tablesMap             = [];
	public $dbOptions             = NULL;
	public $defaultsFilePath      = NULL;

	function __construct($options) {
		$this->pdo       = $options['pdo'];
		$this->database  = $options['dbOptions']->database->database;
		$this->dbOptions = $options['dbOptions'];
	}

	function writeDefaultsFile() {

		$this->defaultsFilePath = tempnam('/tmp/', 'defaults_');
		$fh = fopen($this->defaultsFilePath, 'w');


		fwrite($fh, "[client]" . PHP_EOL);
		fwrite($fh, "user=" . $this->dbOptions->database->username . PHP_EOL);
		fwrite($fh, "password=".$this->dbOptions->database->password . PHP_EOL);
		fwrite($fh, "protocol=tcp" . PHP_EOL);
		fwrite($fh, "port=3306" . PHP_EOL);
		fwrite($fh, "" . PHP_EOL);
		fwrite($fh, "[mysqldump]" . PHP_EOL);
		fwrite($fh, "quick" . PHP_EOL);
		fwrite($fh, "user=".$this->dbOptions->database->username . PHP_EOL);
		fwrite($fh, "password=".$this->dbOptions->database->password . PHP_EOL);

		fclose($fh);

	}

	function cleanUpDefaultsFile() {
		if(file_exists($this->defaultsFilePath)) unlink($this->defaultsFilePath);
	}

	/**
	 * Reorder XMLSorter::tablesMap so that $depdendentTable is below $table
	 * (we move $table higher, so it's loaded first).
	 * 
	 **/

	public function reorderTableMap($depdendentTable, $table) {

		if($this->verbosity > 0) printf("$table should be above $depdendentTable...");

		//Find $depdendency, the table needs to be above this.
		$dependentIndex = array_search($depdendentTable, $this->tablesMap);

		//Figure out where the table is right now.
		$tableIndex = array_search($table, $this->tablesMap);


		//If the dependentTable is below the Table, quit, nothing to do.

		if($tableIndex < $dependentIndex) {
			if($this->verbosity > 0) printf("OK\n");
			return true;
		}

		//Oh, it's not. So, let's re-arrange the array by swapping them.

		//Split the array based on the top value using array_slice to get the "top" of the array all the way up to the "top" element, but not including it.

		$top = array_slice($this->tablesMap, 0, $dependentIndex);

		$bottom = array_slice($this->tablesMap, $dependentIndex);

		//Now that we have to two parts, grab the $dependentTable from the bottom (removing it)

		$pointer = array_search($table, $bottom);

		$buffer = array_splice($bottom, $pointer,1);

		//Append it to the top
		array_push($top, $buffer[0]);

		//join them back together:

		$final = array_merge($top,$bottom);

		$newIndex = array_search($table,$final);

		$this->tablesMap = $final;

		if($this->verbosity > 0) printf("SWAPPED $buffer[0] @ $tableIndex to $newIndex \n");


	}

	/**
	 * Discover tables that have a foreign key, and populates them into the array XMLSorter::tablesWithForeignKeys 
	 **/

	public function discoverTables() {
		$sql = "SHOW TABLES";

		$stmt = $this->pdo->prepare($sql);

		$result = $stmt->execute();

		if(!$result) {
		    var_dump($stmt->errorInfo());
		    die(__FILE__ . ":" . __LINE__);
		}
		
		$data = $stmt->fetchAll(PDO::FETCH_COLUMN);

		foreach($data as $table) {

			array_push($this->tablesMap,$table);

			if($this->verbosity > 0) printf("Checking: %s\n", $table);
			if($this->hasForeignKeys($table)) {
				if($this->verbosity > 0) printf("+ %s has foreign keys!\n",$table);
				array_push($this->tablesWithForeignKeys, $table);
			}
		}

		printf("Tables with foreign keys:\n");

		foreach($this->tablesWithForeignKeys as $table) {
			printf("%s referenced by (must be above):\n", $table);

			$keys = $this->getForeignKeys($table);

			foreach($keys as $dependentTable) {
				printf("  -%s\n", $dependentTable);

				//Re-order the table map so that $table is above $dependentTable.
				$this->reorderTableMap($dependentTable,$table);
			}

			echo PHP_EOL;
		}

		printf("Resulting TableMap\n");

		foreach($this->tablesMap as $table) {
			print $table . PHP_EOL;
		}
		
	}

	public function getForeignKeys($table) {
		$sql = <<<EOQ
SELECT 
  DISTINCT TABLE_NAME
FROM
  INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
  REFERENCED_TABLE_SCHEMA = ? AND
  REFERENCED_TABLE_NAME = ?;
EOQ;

		$stmt = $this->pdo->prepare($sql);
		$values = [$this->database,$table];
		$result = $stmt->execute($values);

		if(!$result) {
		    var_dump($stmt->errorInfo());
		    die(__FILE__ . ":" . __LINE__);
		}

		$keys = $stmt->fetchAll(PDO::FETCH_COLUMN);

		// if(count($keys) > 0) {
		// 	var_dump($keys);
		// 	die(__FILE__ . ":" . __LINE__);
		// }
		return $keys;		
	}

	public function hasForeignKeys($table) {

		$keys = $this->getForeignKeys($table);
		return (bool) (count($keys)> 0);
		
	}

	public function getNode($needle, $haystack) {
		if($this->verbosity > 0) printf("Getting node: %s" . PHP_EOL, $needle);
		$database = $haystack->getElementsByTagName('table_data');
		foreach($database as $node) {
			if($node->getAttribute('name') == $needle) return $node;
		}
	}

	public function deleteChildren($node) { 
	    while (isset($node->firstChild)) { 
	        $this->deleteChildren($node->firstChild); 
	        $node->removeChild($node->firstChild); 
	    } 
	} 	

	/**
	 * Reads a source testing database, determines what tables have foreign
	 * keys, and sorts the data in the corresponding XML file so that those
	 * foreign keys are satisfied upon import during system testing.
	 * 
	 * Assumes that $pathToXMLData points to a file generated using the following command:
	 * 
	 *   mysqldump --xml -t -u [username] --password=[password] [database] > /path/to/file.xml
	 * 
	 * as noted in https://phpunit.de/manual/current/en/database.html#database.understanding-datasets-and-datatables. 
	 * 
	 * @param $pathToXMLData string The path to the XML file that was dumped from the testing database.
	 **/

	function dumpeOrderedXML($pathToXMLData = 'tmp/dump.xml') {
		//Discover the tables that have foriegn keys.
		$this->discoverTables();

		//Create the reverse list so we can FIFO them...
		$tableList = implode(' ', $this->tablesMap);

		$this->writeDefaultsFile();

		$cmd = "mysqldump --defaults-file=$this->defaultsFilePath --xml -t  $this->database $tableList > $pathToXMLData";

		$result = exec($cmd);

		echo $result . PHP_EOL;

		echo "MySQL dump saved to: $pathToXMLData" . PHP_EOL;

		$this->cleanUpDefaultsFile();

	}


}