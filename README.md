# smart-sort-mysql-dump
Analyzes a database to determine foreign key relationshps, and then exports those tables in the order that allows foreign key relationships to be imported properly in PHPUnit.

#How to use:

1. Copy database.json.sample to database.json
2. Change the contents of database.json to the required values to connect to your testing database.
3. Execute *./sort-xml.php --smartdump /path/to/dump.xml*

The script will connect to the database, determine forigeng keys, and sort the
tables so that tables that have foreign keys to another table will appear
_below_ the tables with the source foreign key. In this way, PHPUnit will
import the tables in the _proper order_ during unit testing.

#Other options:

The --discover command line option will connect to the database and show you how it plans to sort the tables.

The -v flag will cause the script to output debugging information so you can see what decisions it made during the sorting process.
