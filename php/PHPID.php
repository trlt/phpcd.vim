<?php
namespace PHPCD;

use Psr\Log\LoggerInterface as Logger;
use Lvht\MsgpackRpc\Server as RpcServer;
use Lvht\MsgpackRpc\Handler as RpcHandler;
use Zend\Db\Adapter\Adapter;
use Zend\Db\ResultSet\ResultSet;
use Zend\Db\Sql\Ddl;
use Zend\Db\Sql\Sql;
use Zend\Db\TableGateway\TableGateway;

class PHPID implements RpcHandler
{
    /**
     * @var Adapter
     */
    private $db;

    /**
     * @var RpcServer
     */
    private $server;

    /**
     * @var TableGateway
     */
    private $tblClasses;

    /**
     * @var Logger
     */
    private $logger;

    private $root;

    public function __construct($root, Logger $logger)
    {
        $this->root = $root;
        $this->logger = $logger;
    }

    private function createTables(){
        $table = new Ddl\CreateTable('classes');
        $table->addColumn(new Ddl\Column\Varchar('filepath',255));
        $table->addColumn(new Ddl\Column\Varchar('name',255));

        $sql = new Sql($this->db);
        $this->db->query(
            $sql->getSqlStringForSqlObject($table),
            $this->db::QUERY_MODE_EXECUTE
        );
    }

    public function setServer(RpcServer $server)
    {
        $this->server = $server;
    }

    /**
     * update index for one class
     *
     * @param string $class_name fqdn
     */
    public function update($class_name)
    {
        list($parent, $interfaces,$relFilename)
                                            = $this->getClassInfo($class_name);
        if($parent){
            $parent = [$parent];
        }else{
            $parent = [];
        }
        $this->initDb();
        $this->tblClasses->delete(['filepath' => $relFilename]);

        $this->_update($relFilename, $class_name, $parent, $interfaces);
        $this->logger->debug("Update runned!");
    }

    private function _update($relFilename,$class_name, $parents, $interfaces)
    {
        $this->tblClasses->insert([
            'filepath' => $relFilename,
            'name' => $class_name
        ]);

        foreach ($parents as $parent) {
            $this->updateParentIndex($parent, $class_name);
        }
        foreach ($interfaces as $interface) {
            $this->updateInterfaceIndex($interface, $class_name);
        }
    }

    /**
     * Fetch an interface's implemation list,
     * or an abstract class's child class.
     *
     * @param string $name name of interface or abstract class
     * @param bool $is_abstract_class
     *
     * @return [
     *   'full class name 1',
     *   'full class name 2',
     * ]
     */
    public function ls($name, $is_abstract_class = false)
    {
        $base_path = $is_abstract_class ? $this->getIntefacesDir()
            : $this->getExtendsDir();
        $path = $base_path . '/' . $this->getIndexFileName($name);
        if (!is_file($path)) {
            return [];
        }

        $list = json_decode(file_get_contents($path));
        if (!is_array($list)) {
            return [];
        }

        sort($list);

        return $list;
    }

    /**
     * Fetch and save class's interface and parent info
     * according the autoload_classmap.php file
     */
    public function index()
    {
        $this->initDb();
        $this->initIndexDir();

        $files = $this->searchPhpFileList($this->root);

        $count = count($files);
        $last = 0;
        $cut = strlen($this->root) + 1;
        for ($i = 0; $i < $count; $i++) {
            //a fork is used to do not interrupt the whole indexing process
            //in case something bad occurs by the following steps
            //the fork is NOT used to speedup things
            $pid = pcntl_fork();
            if(! $pid){
                 $name = Parser::getClassName($files[$i]);
                 $this->logger->info('Name: ' . $name);
                if(! is_null($name)){
                    $relFilename = substr($files[$i],$cut);
                     try {
                         $interfaces = class_implements($name) ?: [];
                         $parents = class_parents($name) ?: [];
                         $this->_update(
                             $relFilename,
                             $name,
                             $parents,
                             $interfaces
                        );
                     } catch (\Throwable $ignore) {
                     }
                }
                //The child has done its job and musst die now
                //otherwise it will do more jobs as expected
                die();
            }
            $status = 0;
            pcntl_waitpid(0,$status);

            $percent = number_format(($i + 1) / $count * 100);
            if ($percent != $last) {
                 $this->server->call('vim_command', ["redraw | echo \"indexing $percent%\""]);
                 $last = $percent;
            }
        }
        $this->server->call('vim_command', ["redraw | echo \"\""]);
    }

    public static function searchPhpFileList($folder)
    {
        $iterator = new \RecursiveDirectoryIterator($folder);
        $iterator = new \RecursiveIteratorIterator($iterator);
        $iterator = new \RegexIterator($iterator, '/\.php$/i', \RegexIterator::MATCH);

        $files = [];
        foreach ($iterator as $info) {
            $files[] = $info->getPathName();
        }

        return $files;
    }

    private function getIndexDir()
    {
        return $this->root . '/.phpcd';
    }

    private function getIntefacesDir()
    {
        return $this->getIndexDir() . '/interfaces';
    }

    private function getExtendsDir()
    {
        return $this->getIndexDir() . '/extends';
    }

    private function initDb(){
        if($this->db instanceof Adapter){ return; }
        $this->db = DbFactory::getDb($this->root);
        if(false === $this->db){
            $this->db = DbFactory::getDb($this->root,true);
            $this->createTables();
        }

        $this->tblClasses = new TableGateway('classes',$this->db);
    }

    private function initIndexDir()
    {
        $extends_dir = $this->getExtendsDir();
        if (!is_dir($extends_dir)) {
            mkdir($extends_dir, 0700, true);
        }

        $interfaces_dir = $this->getIntefacesDir();
        if (!is_dir($interfaces_dir)) {
            mkdir($interfaces_dir, 0700, true);
        }
    }

    private function updateParentIndex($parent, $child)
    {
        $index_file = $this->getExtendsDir() . '/' . $this->getIndexFileName($parent);
        $this->saveChild($index_file, $child);
    }

    private function updateInterfaceIndex($interface, $implementation)
    {
        $index_file = $this->getIntefacesDir() . '/' . $this->getIndexFileName($interface);
        $this->saveChild($index_file, $implementation);
    }

    private function saveChild($index_file, $child)
    {
        $index_directory = dirname($index_file);

        if (!is_dir($index_directory)) {
            mkdir($index_directory, 0755, true);
        }

        if (is_file($index_file)) {
            $childs = json_decode(file_get_contents($index_file));
        } else {
            $childs = [];
        }

        $childs[] = $child;
        $childs = array_unique($childs);
        file_put_contents($index_file, json_encode($childs));
    }

    private function getIndexFileName($name)
    {
        return str_replace("\\", '_', $name).'.json';
    }

    /**
     * retruns an array with the following keys
     * 0 = string|false parent-classname or false in case it has no parent
     * 1 = array interfaces
     * 2 = string filepath relative to project root
     *
     * @return array
     */
    private function getClassInfo($name) {
        try {
            $reflection = new \ReflectionClass($name);

            $parent = $reflection->getParentClass();
            if ($parent) {
                $parent = $parent->getName();
            }

            $interfaces = array_keys($reflection->getInterfaces());
            $relFilename = $reflection->getFileName();
            $relFilename = substr($relFilename,strlen($this->root) + 1);
            return [$parent, $interfaces,$relFilename];
        } catch (\Exception $e) {
            return [false, [], false];
        }
    }
}
