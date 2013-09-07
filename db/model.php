<?php

// 全局变量暂时放在这儿, TODO 统一入口后, 放在入口处
define('FILE_PATH', dirname(dirname(__FILE__)));
define('BASE_PATH', FILE_PATH.'/');

// 是否开启调试  设为true 可以看到memcache的工作情况
define('DEBUG',true);
	
class Model{

	private $conf = array();

	private static $db;
	private static $cache;

	private $last_tablename;

	/**
	 * [__construct 构造函数 解析配置数组]
	 * conf为配置文件，格式为php数组
	 */
	function __construct(){
		$conf = include BASE_PATH.'conf/conf.php';

		$this->conf = &$conf;
	}

	/**
	 * [__destruct 析构函数]
	 *
	 * 更新cache数据
	 */
	function __destruct (){
		if (is_string($this->last_tablename))
			$this->update($this->last_tablename);
	}

	/**
	 * [__get 魔术方法]
	 * @param  [type] $var [如果没有$var变量则生成一个]
	 * @return [type]      [description]
	 */
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

	/**
	 * [维持一个db的句柄  单例模式]
	 * @return [object] [db句柄]
	 */
	public function get_db_instance(){
		$db_conf = $this->conf['db'];

		if(isset(self::$db))
			return self::$db;
		else{
			$db_name = 'db_pdo_'.$db_conf['type'];
			require_once BASE_PATH.'db/db/'.$db_name.'.class.php';
			self::$db = new $db_name($db_conf['mysql']['master']);
			return self::$db;
		}
	}

	/**
	 * [维持一个memcache的句柄  单例模式]
	 * @return [object] [memcache句柄]
	 */
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
	/**
	 * [query db_cache_query 判断是否在cache中，是查询缓存，否查询数据库，然后插入缓存]
	 * @param  [string]  $sql  [sql语句]
	 * @param  boolean $flag [是否多表查询]
	 * @return [array]        [关联数组]
	 */
	public function query_old($sql, $flag = true){
		if($this->conf['cache']['enable']) {
			$arr = $this->get_cache_instance()->get($sql);
			if(empty($arr)) {
				$arrlist = $this->get_db_instance()->query($sql,$flag);

				$this->get_cache_instance()->set($sql, $arrlist);
				return $arrlist;
			} else {
				return $arr;
			}
		} else {
			return $this->get_db_instance()->query($sql,$flag);
		}
	}

	/**
	 * [query_new db_cache_query 判断是否在cache中，是查询缓存，否查询数据库，然后插入缓存]
	 * @param  [string]  $sql  [sql语句]
	 * @param  boolean $flag [是否多表查询]
	 * @return [array]        [关联数组]
	 *
	 * TODO 如果查询db的sql也为空怎么办？
	 *
	 * 对于过于复杂的sql语句可以通过传参的方式传入表名
	 */
	public function query($param,$flag = true){

		// 对于复杂sql的表名通过传参的方式进行获取
		if (is_array($param)){
			$sql = $param[0];
			$tablename = $param[1];
		}else{
			$sql = $param;
			$tablename = "";
		}

		if($this->conf['cache']['enable']) {

			$key = $this->generate_key($sql);

			if(DEBUG){
				echo "<pre>";
				echo "debug from file : <b>".basename(__FILE__)."</b> , invoke method is <b>".__METHOD__."</b> , and print lineno  is <b>".__LINE__."</b> <br />";
				echo "model query ...<br />";
				echo "sql is : {$sql}<br />";
				if($tablename != "")
					echo "tablename is given by args : {$tablename}<br />";
				else
					echo "tablename is not given by args....<br />";
				echo "</pre>";
			}
			
			// 判断是否在cache中
			$arr = $this->get_cache_instance()->get($key);

			if(empty($arr)) {

				// 查询db
				$arrlist = $this->get_db_instance()->query($sql,$flag);
				
				// TODO $arrlist为空的情况

				// 获取表名
				if ($tablename != "")
					$tables = array($tablename);
				else
					$tables = $this->get_select_table_name($sql);

				// 将表名和sql组成一个cache对
				foreach($tables as $tableName)
					$this->get_cache_instance()->add_table_value($tableName, array($key,$sql,$flag));

				$this->get_cache_instance()->set($key, $arrlist);
				
				return $arrlist;
			} else {
				return $arr;
			}
		} else {
			return $this->get_db_instance()->query($sql,$flag);
		}
	}

	/**
	 * [db_query 无需缓存直接进行数据库操作]
	 * @param  [string]  $sql  [sql语句]
	 * @param  boolean $flag [是否多行 true为多行 false为单行]
	 * @return [mix]        [关联数组 结果集]
	 */
	public function db_query($sql, $flag = true){
		return $this->get_db_instance()->query($sql,$flag);
	}

	/**
	 * [flush_all 清空所有缓存]
	 * @return [type] [description]
	 */
	public function flush_all(){
		if($this->conf['cache']['enable']) {

			$this->get_cache_instance()->truncate();
		}
	}

	/**
	 * [execute 执行数据库操作语句(insert, update等)]
	 * @param  [string] $sql [sql语句]
	 * @return [int]      [操作的行数]
	 */
	public function execute($sql){
		if($this->conf['cache']['enable']) {
			$this->get_db_instance()->exec($sql);

			$tablename = $this->get_exec_table_name($sql);
			if ($this->last_tablename == null)
				$this->last_tablename = $tablename;
			
			if ($this->last_tablename != $tablename){
				$this->update($this->last_tablename);
				$this->last_tablename = $tablename;
			}
		}else{
			$this->get_db_instance()->exec($sql);
		}
	}

	/**
	 * [update 对于执行增删改操作的表需要更新缓存]
	 * @param  [string] $tablename [需要更新的表的表名]
	 * @return [null]      [暂无返回]
	 *
	 * 主要操作时
	 */
	public function update($tablename){

		// 取得所有与该表相关的sql语句
		$keys = $this->get_cache_instance()->get($tablename);

		foreach($keys as $key){
			$cache_key = $key[0];
			$sql = $key[1];
			$flag = $key[2];

			// 查询数据库
			$arrlist = $this->get_db_instance()->query($sql,$flag);

			// 更新缓存
			$this->get_cache_instance()->update($cache_key,$arrlist);
		}

	}

	/**
	 * [generate_key 生成唯一的key值]
	 * @param  [string] $sql [sql语句]
	 * @return [string]      [hash后的key值]
	 *
	 * TODO应该生成更有意义的值
	 */
	public function generate_key($sql){
		return md5($sql);
	}

	/**
	 * [get_table_name 根据sql语句得到表名]
	 * @param  [string] $sql [sql语句]
	 * @return [string]      [表名]
	 *
	 * example:
	 * $sql = "SELECT * from `test`, `aa` where `a` = 1";
	 * $sql = "select from a, b";
	 * $sql = "select from a where a = 1";
	 * $sql = "select from a,b,c where a = 2";
	 *
	 * TODO 此处未考虑子查询等复杂sql语句的情况
	 */
	private function get_select_table_name($sql){

		// 预处理
		$sql = $this->prepare_sql($sql);

		// 取出from和where之间的表名
		$sql_after = substr($sql, strrpos($sql, "from")+4, strlen($sql));
		
		// TODO 取出from后面的表名
		// testcase ：select * from app_posts order by date desc limit 0,5 
		if(strpos($sql_after,'where')){   // 如果有where子句
			$tables = substr($sql_after, 0, strpos($sql_after,'where'));
		}else if (strpos($sql_after,'order')){
			$tables = substr($sql_after, 0, strpos($sql_after,'order'));
		}else if (strpos($sql_after,'limit')){
			$tables = substr($sql_after, 0, strpos($sql_after,'limit'));
		}else{   // 如果没有
			$tables = $sql_after;
		}

		$tables = str_replace(" ", "", $tables);
		$tables = explode(",", $tables);

		if(DEBUG){
			echo "<pre>";
			echo "debug from file : <b>".basename(__FILE__)."</b> , invoke method is <b>".__METHOD__."</b> , and print lineno  is <b>".__LINE__."</b> <br />";
			echo "get select tablename : <br />";
			print_r($tables);
			echo "</pre>";
		}

		return $tables;
	}

	/**
	 * [get_exec_table_name 根据insert/update/delete等操作取出表名]
	 * @param  [string] $sql [sql语句]
	 * @return [string]      [表名]
	 *
	 * 该操作特点都只能操作一张表, 返回值必为一个表名
	 * 
	 * example
	 * INSERT into `tablename`(a) set('1')
	 * insert into tablename set(1,2,3)
	 *
	 * update `tablename` set a = 1 where id = 1
	 *
	 * delete from `tablename` where a = 1
	 */
	private function get_exec_table_name($sql){
		
		$sql = $this->prepare_sql($sql);

		if(substr($sql,0,6) == "insert"){
			$reg = '/^.*into\s+(\w+).*$/';

			preg_match($reg, $sql, $matches);

			return is_string($matches[1]) ? $matches[1] : "";
		}
		else if (substr($sql,0,6) == "update"){
			$reg = '/^.*update\s+(\w+).*$/';

			preg_match($reg, $sql, $matches);

			return is_string($matches[1]) ? $matches[1] : "";

		}else if (substr($sql,0,6) == "delete"){
			$reg = '/^.*from\s+(\w+).*$/';

			preg_match($reg, $sql, $matches);

			return is_string($matches[1]) ? $matches[1] : "";
		}else
			return "";
	}

	private function prepare_sql($sql){
		// 去除'`'
		$sql = str_replace("`", "", $sql);

		// 统一为小写
		$sql = strtolower($sql);

		return $sql;
	}

	//-------------------------------------------------------------------
	// TODO
	// 下面的方法是尝试对memcache按每条记录进行处理
	// 不过需解决初始化问题
	// 初始化问题具体是指 : select * from table; 获取的多条数据如何存入memcache并且可以随时取出
	// 方法一： select * 出现的场景多为首页和列表页，而这些页面大多分页呈现
	// 可以先想memcache中放入首页需要呈现的列表结果值，如table_list => (1,2,3,4,5)
	// 
	// 方法二：
	// 通过将sql查询多表进行分解
	// 先查出结果集然后对结果集的ids插入memcache中，然后对每个id分别插入memcache中

	// clong 2013/6/16

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