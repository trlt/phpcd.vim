<?php
/**
 * It is not possible to index this class
 *
 * The indexer musst go over this 
 */

namespace PHPCD\A;

class HardForTheIndexer implements NotExistingIface{

	public function __construct(){
		die();
	}

	public function bar(){
		return "foo";
	}
}
