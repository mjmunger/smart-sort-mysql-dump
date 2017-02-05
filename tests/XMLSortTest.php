<?php
use PHPUnit\Framework\TestCase;

class StackTest extends TestCase
{
    public function testDatabaseConfig() {
    	$databaseConfigFile = 'database.json';
    	$this->assertFileExists($databaseConfigFile);

    	$databaseInfoJSON = file_get_contents($databaseConfigFile);

    	$this->assertJson($databaseInfoJSON);

    	$databaseInfo = json_decode($databaseInfoJSON);

    }
}