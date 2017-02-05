#!/usr/bin/env php
<?php 

include("XmlSorter.class.php");

$shortopts  = "";
$shortopts .= "v::d::";  // optional values

$longopts  = array(
    "discover::",     // optional value
    "smartdump:",     // Required value
);

$commandLineOptions = getopt($shortopts, $longopts);

if(!file_exists('database.json')) die("You must create and configure database.json so I can connect to the database.");

$options = json_decode(file_get_contents('database.json'));

$dsn = sprintf('mysql:dbname=%s;host=%s', $options->database->database, $options->database->server);

try {
    $pdo = new PDO($dsn, $options->database->username, $options->database->password);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
}

$XmlSorter = new XmlSorter(['pdo' => $pdo,'database' => $options->database->database]);

if(array_key_exists('v', $commandLineOptions)) $XmlSorter->verbosity = 10;

if(array_key_exists('discover', $commandLineOptions)) $XmlSorter->discoverTables();

if(array_key_exists('smartdump', $commandLineOptions)) $XmlSorter->dumpeOrderedXML($commandLineOptions['smartdump']);