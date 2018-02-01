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
		fwrite($fh, "port=" . $this->dbOptions->database->port . PHP_EOL);
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

	public function smartSortTables() {

		$reordered = false;

		foreach($this->tablesWithForeignKeys as $table) {
			printf("%s referenced by (must be above):\n", $table);

			$keys = $this->getForeignKeys($table);

			foreach($keys as $dependentTable) {
				printf("  -%s\n", $dependentTable);

				//Re-order the table map so that $table is above $dependentTable. If this function returns false, it reordered something, so change / trigger the flag.
				if(!$this->reorderTableMap($dependentTable,$table)) $reordered = true;
			}
		}

		return $reordered;
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

		$reordered = true;
		while($reordered) {
			$reordered = $this->smartSortTables();
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

	function countRows() {
		$counts = [];

		foreach($this->tablesMap as $table) {
			$sql = "SELECT COUNT(*) as rowCount FROM $table";
			$stmt = $this->pdo->prepare($sql);
			$result = $stmt->execute();

			if(!$result) continue;

			$row = $stmt->fetchObject();

			$counts[$table] = (int) $row->rowCount;
		}

		asort($counts);

		foreach($counts as $table => $rowCount) {
			printf("%s %s" . PHP_EOL, str_pad($rowCount, 6), $table);
		}
	}

	function formatBytes($bytes, $precision = 2) { 

	    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 

	    $bytes = max($bytes, 0); 
	    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
	    $pow = min($pow, count($units) - 1); 


	    // Uncomment one of the following alternatives
	     $bytes /= pow(1024, $pow);
	    // $bytes /= (1 << (10 * $pow)); 

	    return round($bytes, $precision) . ' ' . $units[$pow]; 
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

	function dumpOrderedXML($pathToXMLData = 'tmp/dump.xml') {
		//Discover the tables that have foriegn keys.
		$this->discoverTables();

		//Create the reverse list so we can FIFO them...

		//remove any tables that are in the exclude array.
		$targetTables = array_diff($this->tablesMap, $this->dbOptions->exclude);
		$tableList = implode(' ', $targetTables);

		$this->writeDefaultsFile();

		$cmd = "mysqldump --defaults-file=$this->defaultsFilePath --xml -t  $this->database $tableList > $pathToXMLData";

		$result = exec($cmd);

		echo $result . PHP_EOL;

		echo "MySQL XML dump saved to: $pathToXMLData" . PHP_EOL;

		//Dump the compliment SQL file for easy restoration.

		$cmd = "mysqldump --defaults-file=$this->defaultsFilePath $this->database $tableList > $pathToXMLData.restore.sql";
		$result = exec($cmd);
		echo $result . PHP_EOL;
		echo "MySQL SQL dump saved to: $pathToXMLData.restore.sql" . PHP_EOL;

		$this->cleanUpDefaultsFile();

		$file = new SplFileInfo($pathToXMLData);

		if($file->getSize() > (1024 * 2^10 * 2)) {
			printf("$pathToXMLData (%s) created.", $this->formatBytes($file->getSize()));
			echo PHP_EOL;
			echo PHP_EOL;
			printf("Your XML data file is larger than 2 MB. Consider optimizing");
			echo PHP_EOL;
			printf("it by truncating any tables not specifically in use by this");
			echo PHP_EOL;
			printf("test. Below is a list of tables with their rowcounts:");
			echo PHP_EOL;
			$this->countRows();

		}

	}


    public function createConfig() {
        $handle = fopen ("php://stdin","r");
        print "Enter database username: " ;
        $user = trim(fgets($handle));

        print "Enter database password: " ;
        $pass = trim(fgets($handle));

        print "Enter server address: " ;
        $server = trim(fgets($handle));

        print "Enter database name: " ;
        $dbname = trim(fgets($handle));

        fclose($handle);

        $database = [ 'username' => $user
                    , 'password' => $pass
                    , 'server'   => $server
                    , 'database' => $dbname
                    ];
        $exclude = [];

        $configs = [];
        $configs['database'] = $database;
        $configs['exclude' ] = $exclude;

        $buffer = json_encode($configs);

        $handle = fopen('database.json', 'w');
        fwrite($handle, $buffer);
        fclose($handle);

        print "database.json has been written.";

        if(file_exists('.gitignore')) {
            $handle = fopen('.gitignore','a');
            fwrite($handle,'database.json' . PHP_EOL);
            fclose($handle);

            print ".gitgnore was found, so database.json has been added to it.";
        }

        exit();

    }
}