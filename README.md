DB Broker - interface for working with DB. Supports mysql, oracle and postgresql for now. 
=============================

Installation:
------------------
1.  Put following lines into `require` section of your `composer.json`
```
"pfilsx/db-broker" : "*"
```
2. Put following lines into `repositories` section of your `composer.json`
```
{
    "type": "git",
    "url": "https://github.com/pfilsx/db-broker.git"
}
```
Configuring:
------------------
```
return [
    'dsn' => 'oci8:dbname=...',
    'username' => '...',
    'password' => '...',
    'charset' => '...',
    'attributes' => [
        ...
    ]
];
```
Usage:
------------------
```
$config = [...];
$connection = new pfilsx\db_broker\Connection($config);
$query = $connection->createQuery();
$result = $query->select(['ID_TEST', 'TEST_TEXT'])->from('TESTS')->where(['IS_ACTIVE' => 1])->all();
```

The code above will build next query:
```
SELECT `ID_TEST`, `TEST_TEXT` FROM `TESTS` WHERE `IS_ACTIVE` = :is_active
```
Where `:is_active` will be replaced with 1.
