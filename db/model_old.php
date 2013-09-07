<?php

// 最早的一个设想的备份
// 通过将sql查询多表进行分解
// 先查出结果集然后对结果集的ids插入memcache中，然后对每个id分别插入memcache中
// clong 2013/6/15

define('FILE_PATH', str_replace('\\', '/', getcwd()).'/');
define('BASE_PATH', FILE_PATH.'../');
	
class Model{

	private $conf = array();

	private static $db;
	private static $cache;

	function __construct(){
		$conf = include BASE_PATH.'conf/conf.php';

		$this->conf = &$conf;
	}

	function __get($var) {
		if($var == 'db') {
			$this->$var = $this->get_db_instance();
			return $this->$var;
		} elseif($var == 'cache') {
			$this->$var = $this->get_cache_instance();
			return $this->$var;
		}
	}

	// method missing 方法
	function __call($method, $parms) {
		throw new Exception("$method does not exists.");
	}

	public function get_db_instance(){
		$db_conf = $this->conf['db'];

		if(isset(self::$db))
			return self::$db;
		else{
			$db_name = 'db_'.$db_conf['type'];
			require_once BASE_PATH.'db/db/'.$db_name.'.class.php';
			self::$db = new $db_name($db_conf['mysql']['master']);
			return self::$db;
		}
	}

	public function get_cache_instance(){

		$cache_conf = $this->conf['cache'];

		if(isset(self::$cache))
			return self::$cache;
		else{
			$cache_name = 'cache_'.$cache_conf['type'];
			require_once BASE_PATH.'db/cache/'.$cache_name.'.class.php';
			self::$cache = new $cache_name($cache_conf['memcache']);
			return self::$cache;
		}
	}


	public function query($sql){
		if($this->conf['cache']['enable']) {

			// $key = $this->generate_key($sql);
			
			$arr = $this->cache_get($sql);

			if(!$arr) {
				$arrlist = $this->get_db_instance->query($sql);
				// $key = $this->generate_key($sql);

				// 更新到 cache
				$this->cache_set($sql, $arrlist);
				return $arrlist;
			} else {
				return $arr;
			}
		} else {
			return $this->db_get($sql);
		}
	}


	public function db_get($key) {
		return $this->get_db_instance()->get($key);
	}
	
	public function db_set($key, $data) {
		return $this->get_db_instance()->set($key, $data);
	}
	
	public function db_update($key, $data) {
		return $this->get_db_instance()->update($key, $data);
	}
	
	public function db_delete($key) {
		return $this->get_db_instance()->delete($key);
	}

	public function cache_get($key) {
		return $this->get_cache_instance()->get($key);
	}
	
	public function cache_set($key, $data) {
		return $this->get_cache_instance()->set($key, $data);
	}
	
	public function cache_update($key) {
		return $this->get_cache_instance()->update($key, $data);
	}
	
	public function cache_delete($key) {
		return $this->get_cache_instance()->delete($key);
	}


	public function db_cache_get($key) {
		if($this->conf['cache']['enable']) {
			$arr = $this->cache_get($key);
			if(!$arr) {
				$arrlist = $this->db_get($key);
				// 更新到 cache
				if(is_array($key)) {
					foreach((array)$arrlist as $k=>$v) {
						$this->cache_set($k, $v);
					}
				} else {
					$this->cache_set($key, $arrlist);
				}
				return $arrlist;
			} else {
				return $arr;
			}
		} else {
			return $this->db_get($key);
		}
	}
	
	public function db_cache_set($key, $data, $life = 0) {
		$this->conf['cache']['enable'] && $this->cache_set($key, $data, $life);	// 更新缓存
		return $this->db_set($key, $data);
	}

	public function db_cache_update($key, $data) {
		$this->conf['cache']['enable'] && $this->cache_update($key, $data);	// 更新缓存
		return $this->db_update($key, $data);
	}

	public function db_cache_delete($key) {
		$this->conf['cache']['enable'] && $this->cache_delete($key);
		return $this->db_delete($key);
	}

	// 
	/**
	 * [db_cache_maxid $val == 0，返回最大ID，也可以为 +1]
	 * @param  [type]  $key [表名和求maxid的列名]
	 * @param  boolean $val [description]
	 * @return [type]       [description]
	 */
	public function db_cache_maxid($key, $val = FALSE) {
		// $key = $this->table.'-'.$this->maxcol;
		//$db = $this->get_db_instance($key, $this->conf['db']); // 返回 arbiter 实例
		if($this->conf['cache']['enable']) {
			$this->cache->maxid($key, $val);	// 更新缓存
			return $this->db->maxid($key, $val);
		} else {
			return $this->db->maxid($key, $val);
		}
	}
	

	/**
	 * [db_cache_count 返回总行数，无法 +1]
	 * @param  [type]  $key [表名]
	 * @param  boolean $val [description]
	 * @return [type]       [description]
	 */
	public function db_cache_count($key, $val = FALSE) {
		// $key = $this->table;
		if($this->conf['cache']['enable']) {
			$this->cache->count($key, $val);
			return $this->db->count($key, $val);
		} else {
			return $this->db->count($key, $val);
		}
	}
	
	// -----------------------------> 提供更高层次的封装，所有符合标准的表结构都可以使用以下方法
	
	/*
		获取多行数据：
		$this->user->mget(array(1, 2, 3));					// 如果 primary key 只有一列
		$this->user->mget(array(array(1, 1), array(1, 2), array(1, 3)));	// 如果 primary key 多列	
	*/
	public function mget($keys) {
		// 这里顺序应该没有问题，按照key顺序预留了NULL值。
		$arrlist = array();
		foreach($keys as $k=>$key) {
			$key = $this->to_key($key);
			$keys[$k] = $key;
			if(isset($this->unique[$key])) {
				$arrlist[$key] = $this->unique[$key];
				unset($keys[$k]);
			} else {
				$arrlist[$key] = NULL;
				$this->unique[$key] = $arrlist[$key];
			}
		}
		$arrlist2 = $this->db_cache_get($keys);
		$arrlist = array_merge($arrlist, $arrlist2);
		return $arrlist;
	}
	
	/*
		此接口中的 $key 参数格式不同于 db , cache 中的 get()
		获取一行数据：
		$this->user->get(1);			// 如果 primary key 只有一列
		$this->user->get(array(1, 2));		// 如果 primary key 多列	
	*/
	public function get($key) {
		// 支持数组
		$key = $this->to_key($key);
		if(!isset($this->unique[$key])) {
			$this->unique[$key] = $this->db_cache_get($key);
		}
		return $this->unique[$key];
	}
	
	/*
		此接口中的 $key 参数格式不同于 db , cache 中的 set()
		设置一行数据：
		$this->user->set(1, array('username'=>'zhangsan', 'email'=>'zhangsan@gmail.com'));
		$this->user->set(array(1, 2), array('username'=>'zhangsan', 'email'=>'zhangsan@gmail.com'));
	*/
	// $life 参数为过期时间，预留给 model class 重载
	public function set($key, $arr, $life = 0) {
		$key = $this->to_key($key);
		$this->unique[$key] = $arr;
		return $this->db_cache_set($key, $arr);
	}
	
	/*
		创建一行数据：
		$user = array('username'=>'abc', 'email'=>'abc@gmail.com');
		$this->user->create($user);
	*/
	public function create($arr) {
		if(!empty($this->maxcol)) {
			if(!isset($arr[$this->maxcol])) {
				$arr[$this->maxcol] = $this->maxid('+1');	// 自增
			}
			$this->count('+1');
			$key = $this->get_key($arr);
			if($this->set($key, $arr)) {
				$this->unique[$key] = $arr;
				return $arr[$this->maxcol];
			} else {
				$this->maxid('-1');
				$this->count('-1');
				return FALSE;
			}
		} else {
			// 如果没有设置 maxcol, 则执行处理 count(), maxid()
			$key = $this->get_key($arr);
			$this->set($key, $arr);
			$this->unique[$key] = $arr;
			return TRUE;
		}
	}
	
	/*
		更新一行数据：
		$user = $this->user->read(1);
		$user['username'] = 'abc';
		$this->user->update($user);
	*/
	public function update($arr) {
		$key = $this->get_key($arr);
		$this->unique[$key] = $arr;
		return $this->db_cache_update($key, $arr);
	}
	
	/*
		读取一行数据:
		$this->user->read(1);			// 如果 primary key 只有一列
		$this->user->read(array(1, 2));		// 如果 primary key 多列	
		$this->user->read(1, 2);		// 如果 primary key 多列 （更简洁的写法，最多支持4列）
	*/
	public function read($key, $arg2 = FALSE, $arg3 = FALSE, $arg4 = FALSE) {
		// func_get_args() 这个函数有些环境不支持
		$key = (array)$key;
		$arg2 !== FALSE && array_push($key, $arg2);
		$arg3 !== FALSE && array_push($key, $arg3);
		$arg4 !== FALSE && array_push($key, $arg4);
		
		$key = $this->to_key($key);
		return $this->db_cache_get($key);
	}
	
	/*
		删除一行数据:
		$this->user->delete(1);			// 如果 primary key 只有一列
		$this->user->delete(array(1, 2));	// 如果 primary key 多列	
		$this->user->delete(1, 2);		// 如果 primary key 多列 （更简洁的写法，最多支持4列）
	*/
	public function delete($key, $arg2 = FALSE, $arg3 = FALSE, $arg4 = FALSE) {
		// func_get_args() 这个函数有些环境不支持
		$key = (array)$key;
		$arg2 !== FALSE && array_push($key, $arg2);
		$arg3 !== FALSE && array_push($key, $arg3);
		$arg4 !== FALSE && array_push($key, $arg4);
		
		if(!empty($this->maxcol)) {
			$this->count('-1');
		}
		$key = $this->to_key($key);
		unset($this->unique[$key]);
		return $this->db_cache_delete($key);
	}
	
	/*
		获取/设置最大的 maxid
		$this->user->maxid();
		$this->user->maxid(100);
		$this->user->maxid('+1');
		$this->user->maxid('-1');
	*/
	public function maxid($val = FALSE) {
		return $this->db_cache_maxid($val);
	}
	
	/*
		自行计数, 获取/设置最大的 count
		$this->user->count();
		$this->user->count(100);
		$this->user->count('+1');
		$this->user->count('-1');
	*/
	public function count($key, $val = FALSE) {
		// $key = $this->table;
		return $this->db_cache_count($key, $val);
	}

	// 从 arr 中提取 key string
	public function get_key($arr) {
		$s = $this->table;
		foreach($this->primarykey as $v) {
			$s .= "-$v-".$arr[$v];
		}
		return $s;
	}
	
	// 数组 to key
	public function to_key($key) {
		$s = $this->table;
		foreach((array)$key as $k=>$v) {
			$s .= '-'.$this->primarykey[$k].'-'.$v;
		}
		return $s;
	}
}
?>