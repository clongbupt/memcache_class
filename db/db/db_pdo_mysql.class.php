<?php

/*
 * Copyright (C) xiuno.com
 */

class db_pdo_mysql{

	private $conf;
	//private $wlink;	// 读写分离
	//private $rlink;	// 读写分离
	//private $xlink;	// 单点服务器
	public $tablepre;	// 方便外部读取
	
	/**
	 * [__construct 构造函数 解析数据库配置]
	 * conf为配置文件，格式为php数组
	 */
	public function __construct($conf) {
		$this->conf = $conf;
	}
	
	/**
	 * [__get 魔术方法]
	 * @param  [type] $var [如果没有$var变量则生成一个]
	 * @return [type]      [description]
	 *
	 * TODO 读写分离的考虑
	 */	
	public function __get($var) {
		$conf = $this->conf;

		if($var == 'rlink' || $var == 'wlink' || $var == 'xlink'){
			$conf = $this->conf;
			$this->link = $this->connect($conf['host'], $conf['user'], $conf['password'], $conf['name'], $conf['charset'], $conf['engine']);

			return $this->link;
		}
	}
	
	/**
	 * [connect PDO的数据库链接]
	 * @param  [string] $host     [主机名]
	 * @param  [string] $user     [用户名]
	 * @param  [string] $password [密码]
	 * @param  [string] $name     [数据库名]
	 * @param  string $charset  [编码]
	 * @param  string $engine   [引擎]
	 * @return [object]           [数据库连接句柄]
	 */
	public function connect($host, $user, $password, $name, $charset = '', $engine = '') {
		if(strpos($host, ':') !== FALSE) {
			list($host, $port) = explode(':', $host);
		} else {
			$port = 3306;
		}
		try {
			$link = new PDO("mysql:host=$host;port=$port;dbname=$name", $user, $password);
			//$link->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
		} catch (Exception $e) {    
	        	throw new Exception('连接数据库服务器失败:'.$e->getMessage());    
	        }
	        //$link->setFetchMode(PDO::FETCH_ASSOC);
		if($charset) {
			 $link->query('SET NAMES '.$charset.', sql_mode=""');  
		} else {
			 $link->query('SET sql_mode=""');  
		}
		return $link;
	}
	
	/**
	 * [query 数据库查询]
	 * @param  [string] $sql            [sql语句]
	 * @param  [bool] $multi_row_flag [结果集是多行还是单行的标志位]
	 * @return [array]                 [结果集的关联数组]
	 */
	public function query($sql, $multi_row_flag){
		if(DEBUG){
				echo "<pre>";
				echo "debug from file : <b>".basename(__FILE__)."</b> , invoke method is <b>".__METHOD__."</b> , and print lineno  is <b>".__LINE__."</b> <br />";
				echo "get from database ...<br />";
				echo "sql is : {$sql}<br />";
				echo "</pre>";
			}
		$link = $this->rlink;

		$result = $link->query($sql);

		if($result === FALSE) {
			$error = $link->errorInfo();
			throw new Exception('MySQL Query Error:'.$sql.' '.(isset($error[2]) ? "Errstr: $error[2]" : ''));
		}

		// 多行情况
		if($multi_row_flag){
			$result->setFetchMode(PDO::FETCH_ASSOC);
			return $result->fetchAll();
		}else{  //单行情况
			$result->setFetchMode(PDO::FETCH_ASSOC);
			return $result->fetch();
		}
		
	}

	// 返回行数
	public function exec($sql) {
		if(DEBUG){
			echo "<pre>";
			echo "debug from file : <b>".basename(__FILE__)."</b> , invoke method is <b>".__METHOD__."</b> , and print lineno  is <b>".__LINE__."</b> <br />";
			echo "exec from database ...<br />";
			echo "sql is : {$sql}<br />";
			echo "</pre>";
		}

		$n = $this->wlink->exec($sql);
		return $n;
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

	public function get($key) {
		if(!is_array($key)) {
			list($table, $keyarr, $sqladd) = $this->parse_key($key);
			$tablename = $this->tablepre.$table;
       			return $this->fetch_first("SELECT * FROM $tablename WHERE $sqladd");
		} else {
			// 此处可以递归调用，但是为了效率，单独处理
			$sqladd = $_sqladd = $table =  $tablename = '';
			$data = $return = $keyarr = array();
			$keys = $key;
			foreach($keys as $key) {
				$return[$key] = array();	// 定序，避免后面的 OR 条件取出时顺序混乱
				list($table, $keyarr, $_sqladd) = $this->parse_key($key);
				$tablename = $this->tablepre.$table;
				$sqladd .= "$_sqladd OR ";
			}
			$sqladd = substr($sqladd, 0, -4);
			if($sqladd) {
				$sql = "SELECT * FROM $tablename WHERE $sqladd";
				defined('DEBUG') && DEBUG && isset($_SERVER['sqls']) && count($_SERVER['sqls']) < 1000 && $_SERVER['sqls'][] = htmlspecialchars(stripslashes($sql));// fixed: 此处导致的轻微溢出后果很严重，已经修正。
				$result = $this->rlink->query($sql);
				$result->setFetchMode(PDO::FETCH_ASSOC);
				$datalist = $result->fetchAll();
				foreach($datalist as $data) {
					$keyname = $table;
					foreach($keyarr as $k=>$v) {
						$keyname .= "-$k-".$data[$k];
					}
					$return[$keyname] = $data;
				}
			}
			return $return;
		}
	}
	
	// insert -> replace
	public function set($key, $data) {
		list($table, $keyarr, $sqladd) = $this->parse_key($key);
		$tablename = $this->tablepre.$table;
		if(is_array($data)) {
			// 覆盖主键的值
			$data += $keyarr;
			$s = $this->arr_to_sqladd($data);
			return $this->query("REPLACE INTO $tablename SET $s");
		} else {
			return FALSE;
		}
	}
	
	public function update($key, $data) {
		list($table, $keyarr, $sqladd) = $this->parse_key($key);
		$tablename = $this->tablepre.$table;
		$s = $this->arr_to_sqladd($data);
		return $this->query("UPDATE $tablename SET $s WHERE $sqladd");
	}
	
	public function delete($key) {
		list($table, $keyarr, $sqladd) = $this->parse_key($key);
		$tablename = $this->tablepre.$table;
		return $this->query("DELETE FROM $tablename WHERE $sqladd");
	}
	
	/**
	 * 
	 * maxid('user-uid') 返回 user 表最大 userid
	 * maxid('user-uid', '+1') maxid + 1, 占位，保证不会重复
	 * maxid('user-uid', 10000) 设置最大的 maxid 为 10000
	 *
	 */
	public function maxid($key, $val = FALSE) {
		list($table, $col) = explode('-', $key);
		$maxid = $this->table_maxid($key);
		if($val === FALSE) {
			return $maxid;
		} elseif(is_string($val) && $val{0} == '+') {
			$val = intval($val);
			$this->query("UPDATE {$this->tablepre}framework_maxid SET maxid=maxid+'$val' WHERE name='$table'", $this->xlink);
			return $maxid += $val;
		} else {
			$this->query("UPDATE {$this->tablepre}framework_maxid SET maxid='$val' WHERE name='$table'", $this->xlink);
			// ALTER TABLE Auto_increment 这个不需要改，REPLACE INTO 直接覆盖
			return $val;
		}
	}
	public function count($key, $val = FALSE) {
		$count = $this->table_count($key);
		if($val === FALSE) {
			return $count;
		} elseif(is_string($val)) {
			if($val{0} == '+') {
				$val = $count + abs(intval($val));
				$this->query("UPDATE {$this->tablepre}framework_count SET count = '$val' WHERE name='$key'", $this->xlink);
				return $val;
			} else {
				$val = max(0, $count - abs(intval($val)));
				$this->query("UPDATE {$this->tablepre}framework_count SET count = '$val' WHERE name='$key'", $this->xlink);
				return $val;
			}
		} else {
			$this->query("UPDATE {$this->tablepre}framework_count SET count='$val' WHERE name='$key'", $this->xlink);
			return $val;
		}
	}
	
	public function truncate($table) {
		$table = $this->tablepre.$table;
		return $this->query("TRUNCATE $table");
	}
	
	private function cond_to_sqladd($cond) {
		$s = '';
		if(!empty($cond)) {
			$s = ' WHERE ';
			foreach($cond as $k=>$v) {
				if(!is_array($v)) {
					$v = addslashes($v);
					$s .= "$k = '$v' AND ";
				} else {
					foreach($v as $k1=>$v1) {
						$v1 = addslashes($v1);
						$k1 == 'LIKE' && ($k1 = ' LIKE ') && $v1 = "%$v1%";
						$s .= "$k$k1'$v1' AND ";
					}
				}
			}
			$s = substr($s, 0, -4);
		}
		return $s;
	}
	
	private function arr_to_sqladd($arr) {
		$s = '';
		foreach($arr as $k=>$v) {
			$v = addslashes($v);
			$s .= (empty($s) ? '' : ',')."$k='$v'";
		}
		return $s;
	}
	
	public function fetch_first($sql, $link = NULL) {
		defined('DEBUG') && DEBUG && isset($_SERVER['sqls']) && count($_SERVER['sqls']) < 1000 && $_SERVER['sqls'][] = htmlspecialchars(stripslashes($sql));// fixed: 此处导致的轻微溢出后果很严重，已经修正。
		empty($link) && $link = $this->rlink;
		$result = $link->query($sql);
		if($result) {
			$result->setFetchMode(PDO::FETCH_ASSOC);
			return $result->fetch();
		} else {
			$error = $link->errorInfo();
			throw new Exception("Errno: $error[0], Errstr: $error[2]");
		}
	}
	
	/*
		例子：
		table_count('forum');
		table_count('forum-fid-1');
		table_count('forum-fid-2');
		table_count('forum-stats-12');
		table_count('forum-stats-1234');
		
		返回：总数值
	*/
	private function table_count($key) {
		$count = 0;
		try {
			$arr = $this->fetch_first("SELECT count FROM {$this->tablepre}framework_count WHERE name='$key'", $this->xlink);
			$count = intval($arr['count']);
		} catch (Exception $e) {
			$this->query("CREATE TABLE {$this->tablepre}framework_count (
				`name` char(32) NOT NULL default '',
				`count` int(11) unsigned NOT NULL default '0',
				PRIMARY KEY (`name`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci", $this->xlink);
			$this->query("REPLACE INTO {$this->tablepre}framework_count SET name='$key', count='0'", $this->xlink);
		}
		return $count;
	}
	
	/*
		例子：只能为表名
		table_maxid('forum-fid');
		table_maxid('thread-tid');
	*/
	private function table_maxid($key) {
		list($table, $col) = explode('-', $key);
		$maxid = 0;
		try {
			$arr = $this->fetch_first("SELECT maxid FROM {$this->tablepre}framework_maxid WHERE name='$table'", $this->xlink);
			$maxid = $arr['maxid'];
		} catch (Exception $e) {
			
			$r = $this->query("CREATE TABLE `{$this->tablepre}framework_maxid` (
				`name` char(32) NOT NULL default '',
				`maxid` int(11) unsigned NOT NULL default '0',
				PRIMARY KEY (`name`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci", $this->xlink);
			$arr = $this->fetch_first("SELECT MAX($col) as maxid FROM {$this->tablepre}$table", $this->xlink);
			$maxid = $arr['maxid'];
			$this->query("REPLACE INTO {$this->tablepre}framework_maxid SET name='$table', maxid='$maxid'", $this->xlink);
		}
		return $maxid;
	}
	
	private function error($link) {    
		if($link->errorCode() != '00000') {    
			$error = $link->errorInfo();    
			return $error[2];    
		}
		return 0;
	}
	
	private function parse_key($key) {
		$sqladd = '';
		$arr = explode('-', $key);
		$len = count($arr);
		$keyarr = array();
		for($i = 1; $i < $len; $i = $i + 2) {
			if(isset($arr[$i + 1])) {
				$sqladd .= ($sqladd ? ' AND ' : '').$arr[$i]."='".addslashes($arr[$i + 1])."'";
				$t = $arr[$i + 1];// mongodb 识别数字和字符串
				$keyarr[$arr[$i]] = is_numeric($t) ? intval($t) : $t;
			} else {
				$keyarr[$arr[$i]] = NULL;
			}
		}
		$table = $arr[0];
		if(empty($table)) {
			throw  new Exception("parse_key($key) failed, table is empty.");
		}
		if(empty($sqladd)) {
			throw  new Exception("parse_key($key) failed, sqladd is empty.");
		}
		return array($table, $keyarr, $sqladd);
	}
	
	public function __destruct() {
		if(isset($this->wlink)) {
			$this->wlink = NULL;
		}
		if(isset($this->rlink) && $this->rlink != $this->wlink) {
			$this->rlink = NULL;
		}
	}
	
	public function version() {
		return '';// select version()
	}
	
}
?>