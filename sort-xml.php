#!/usr/bin/env php
<?php 

include("XmlSorter.class.php");

function show_help() {
?>

SUMMARY

Reads a database, finds the foreign keys, determines their order of
precedence, and dumps the database tables in the order needed for PHPUnit to
load them properly.

SYNTAX

    ./sort-xml [-v] [--command] [options]

COMMANDS

    -v           Execute in verbose mode.
    --config     Prompts for database connection information to create database.json
    --discover   Discover the tables and sort them in order to support foreign
                 key relationships.
    --dump       Discover the tables ,and dump them in the correct order for
                 PHPUnit to import for testing.
    --restore    Restores the XML dataset SQL file that was created from a smart dump.

EXAMPLES

    dump:
    smart-dump --dump /path/to/dump.xml

    restore:
    smart-dump --restore someXMLDataset


<?php
}

$shortopts  = "";
$shortopts .= "v::d::";  // optional values

$longopts  = array(
    "discover::",     // optional value
    "dump:",     // Required value
    "restore:",     // Required value
    "config::"   //
);

$commandLineOptions = getopt($shortopts, $longopts);

if(array_key_exists('config', $commandLineOptions)) XmlSorter::createConfig();

if(count($commandLineOptions) == 0) show_help();

if(!file_exists('database.json')) die("You must create and configure database.json so I can connect to the database." . PHP_EOL . PHP_EOL);

$options = json_decode(file_get_contents('database.json'));

$dsn = sprintf('mysql:dbname=%s;host=%s;port=%s', $options->database->database, $options->database->server, $options->database->port);

try {
    $pdo = new PDO($dsn, $options->database->username, $options->database->password);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
}

$XmlSorter = new XmlSorter(['pdo' => $pdo, 'dbOptions' => $options]);

if(array_key_exists('v', $commandLineOptions)) $XmlSorter->verbosity = 10;

if(array_key_exists('discover', $commandLineOptions)) $XmlSorter->discoverTables();

if(array_key_exists('dump', $commandLineOptions)) $XmlSorter->dumpOrderedXML($commandLineOptions['dump']);

if(array_key_exists('restore', $commandLineOptions)) $XmlSorter->restoreSQL($commandLineOptions['restore']);

