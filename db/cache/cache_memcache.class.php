<?php

/*
 * Copyright (C) xiuno.com
 */

// if(!defined('FRAMEWORK_PATH')) {
// 	exit('FRAMEWORK_PATH not defined.');
// }

/*
	doc: http://cn2.php.net/memcached.getmulti
	Memcached::getMulti
	PECL memcached >= 0.1.0

*/
class cache_memcache{
	
	public $conf;
	public function __construct($conf) {
		$this->conf = $conf;
	}
	
	//private $memcache;
	private $support_getmulti;
		
	// 仅仅寻找 model 目录
	public function __get($var) {
		if($var == 'memcache') {
			// 判断 Mongo 扩展存在否
			if(extension_loaded('Memcached')) {
				$this->memcache = new Memcached;
			} elseif(extension_loaded('Memcache')) {
				$this->memcache = new Memcache;
			} else {
				throw new Exception('Memcache Extension not loaded.');
			}
			if(!$this->memcache) {
				throw new Exception('PHP.ini Error: Memcache extension not loaded.');
			}
	 		if($this->memcache->connect($this->conf['host'], $this->conf['port'])) {
	 			return $this->memcache;
	 		} else {
	 			throw new Exception('Can not connect to Memcached host.');
	 		}
	 		
	 		$this->support_getmulti = $this->conf['multi'] || method_exists($this->memcache, 'getMulti');
		}
	}

	public function get($key) {

		$data = array(); 
		if(is_array($key)) {
			// 安装的时候要判断 Memcached 版本！ getMulti()
			if($this->support_getmulti) {
				return $this->memcache->getMulti($key);
			} else {
				foreach($key as $k) {
					$arr = $this->memcache->get($k);
					$arr && $data[$k] = $arr;
				}

				return $data;
			}
		} else {
			$data = $this->memcache->get($key);
			if(DEBUG){
				echo "<pre>";
				echo "debug from file : <b>".basename(__FILE__)."</b> , invoke method is <b>".__METHOD__."</b> , and print lineno  is <b>".__LINE__."</b> <br />";
				echo "get from memcache ...<br />";
				echo "key is : {$key}<br />";
				print_r($data);
				echo "</pre>";
			}

			return $data;
		}
	}

	public function add_table_value($tableName, $value){
		if(DEBUG){
				echo "<pre>";
				echo "debug from file : <b>".basename(__FILE__)."</b> , invoke method is <b>".__METHOD__."</b> , and print lineno  is <b>".__LINE__."</b> <br />";
				echo "add_table_sql ...<br />";
				echo "tableName is : {$tableName}<br />";
				echo "value is : ";
				print_r($value);
				echo "</pre>";
			}

		return $this->add_key($tableName, $value);
	}

	private function add_key($p_key, $key){
		$keys=$this->memcache->get($p_key);
		if(empty($keys)){
			$keys=array();
		}
		//如果key不存在,就添加一个
		if(!in_array($key, $keys)) {
			$keys[]=$key;  //将新的key添加到本表的keys中
			$this->memcache->set($p_key, $keys);
			return true;   //不存在返回true
		}else{
			return false;  //存在返回false
		}
	}

	public function clear($p_key){

		$keys=$this->memcache->get($p_key);
		//删除同一个表的所有缓存
		if(!empty($keys)){
			foreach($keys as $key){
				$this->memcache->delete($key); //0 表示立刻删除
			}
		}
		//删除表的所有sql的key
		$this->memcache->delete($p_key); 
	}

	public function set($key, $value, $life = 0) {
		if(DEBUG){
			echo "<pre>";
			echo "debug from file : <b>".basename(__FILE__)."</b> , invoke method is <b>".__METHOD__."</b> , and print lineno  is <b>".__LINE__."</b> <br />";
			echo "set into memcache ...";
			echo "key is : {$key}<br />";
			print_r($value);
			echo "</pre>";
		}
		return $this->memcache->set($key, $value, 0, $life);
	}

	public function update($key, $value) {
		if(DEBUG){
			echo "<pre>";
			echo "debug from file : <b>".basename(__FILE__)."</b> , invoke method is <b>".__METHOD__."</b> , and print lineno  is <b>".__LINE__."</b> <br />";
			echo "update memcache ...";
			echo "key is : {$key}<br />";
			print_r($value);
			echo "</pre>";
		}
		$this->memcache->replace($key, $value);
	}

	public function delete($key) {
		return $this->memcache->delete($key);
	}
	
	public function truncate($pre = '') {
		return $this->memcache->flush();
	}

}
?>