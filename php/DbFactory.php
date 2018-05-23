<?php
namespace PHPCD;

use Zend\Db\Adapter\Adapter;

class DbFactory{

    /**
     * @var Adapter
     */
    private static $dbAdapter;

    /**
     * @param string $root root folder of target project
     * @param bool $force return the adapter event db-file is not exists
     * @return false|Zend\Db\Adapter\Adapter
     */
    public static function getDb($root, $force = false){
        if(! self::$dbAdapter instanceof Adapter){
            $dbFile = self::getIndexDir($root) 
                                        . DIRECTORY_SEPARATOR . 'idx.sqlite3';
            if(! file_exists($dbFile) && ! $force){
                return false;
            }
            $dbConfig = [
                'driver' => 'PDO_Sqlite',
                'database' => $dbFile
            ];
            self::$dbAdapter = new Adapter($dbConfig);
        }
        return self::$dbAdapter;
    }

    private static function getIndexDir($root)
    {
        $root .= DIRECTORY_SEPARATOR . '.phpcd';
        if(! is_dir($root)){
            mkdir($root);
        }
        return $root;
    }
}
