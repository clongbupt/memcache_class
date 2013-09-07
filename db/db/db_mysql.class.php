<?php

/*
 * Copyright (C) xiuno.com
 */

// if(!defined('FRAMEWORK_PATH')) {
// 	exit('FRAMEWORK_PATH not defined.');
// }

// TODO 可以尝试将其转换成mysqli的操作类
// 最开始选择mysql类, 后来发现pdo类, 便转换过去
// clong 2013/6/16

class db_mysql{

	private $conf;
	// private $wlink;	// 读写分离 
	// private $rlink;	// 读写分离
	//private $xlink;	// 单点分发服务器
	public $tablepre;	// 方便外部读取
	
	public function __construct($conf) {
		$this->conf = $conf;
		// $this->tablepre = $this->conf['master']['tablepre'];
	}
		
	public function __get($var) {

		$conf = $this->conf;

		if($var == 'rlink' || $var == 'wlink' || $var == 'xlink'){
			$conf = $this->conf;
			$this->link = $this->connect($conf['host'], $conf['user'], $conf['password'], $conf['name'], $conf['charset'], $conf['engine']);

			return $this->link;
		}
		
		// innodb_flush_log_at_trx_commit
	}
	
	/*
		get('user-uid-123');
		get('user-fid-123-uid-123');
		get(array(
			'user-fid-123-uid-111',
			'user-fid-123-uid-222',
			'user-fid-123-uid-333'
		));
		
		返回：
		array('uid'=>134, 'username'=>'abc')
		或:
		array(
			'user-uid-123'=>array('uid'=>123, 'username'=>'abc')
			'user-uid-234'=>array('uid'=>234, 'username'=>'bcd')
		)
	
	*/
	public function get($key) {
		if(!is_array($key)) {
			list($table, $keyarr, $sqladd) = $this->parse_key($key);
			$tablename = $this->tablepre.$table;
			$result = $this->query("SELECT * FROM $tablename WHERE $sqladd", $this->rlink);
			$arr = mysqli_fetch_assoc($result);
			return $arr;
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
				// todo: 需要判断分库。分库以后，这里会统一在一台DB上取
				$result = $this->query("SELECT * FROM $tablename WHERE $sqladd", $this->rlink);
				while($data = mysqli_fetch_assoc($result)) {
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
	
	// insert & update 整行更新
	public function set($key, $data) {
		list($table, $keyarr, $sqladd) = $this->parse_key($key);
		$tablename = $this->tablepre.$table;
		if(is_array($data)) {
			
			// 以值为准。
			$data += $keyarr;
			$s = $this->arr_to_sqladd($data);
			return $this->query("REPLACE INTO $tablename SET $s", $this->wlink);
		} else {
			return FALSE;
		}
	}
	
	// update 整行更新，可以用来修改主键
	public function update($key, $data) {
		list($table, $keyarr, $sqladd) = $this->parse_key($key);
		$tablename = $this->tablepre.$table;
		$s = $this->arr_to_sqladd($data);
		return $this->query("UPDATE $tablename SET $s WHERE $sqladd", $this->wlink);
	}

	public function delete($key) {
		list($table, $keyarr, $sqladd) = $this->parse_key($key);
		$tablename = $this->tablepre.$table;
		return $this->query("DELETE FROM $tablename WHERE $sqladd", $this->wlink);
	}
	
	/**
	 * 
	 * maxid('user-uid') 返回 user 表最大 uid
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
	
	/* 返回表的总行数
	* count('forum')
	* count('forum-fid-1')
	* count('forum-fid-2')
	*/
	public function count($key, $val = FALSE) {
		$count = $this->table_count($key);
		if($val === FALSE) {
			return $count;
		} elseif(is_string($val)) {
			$count = $this->table_count($key);
			if($val{0} == '+') {
				$val = $count + abs(intval($val));
				$this->query("UPDATE table_count SET count = '$val' WHERE name='$key'", $this->xlink);
				return $val;
			} else {
				$val = max(0, $count - abs(intval($val)));
				$this->query("UPDATE table_count SET count = '$val' WHERE name='$key'", $this->xlink);
				return $val;
			}
		} else {
			$this->query("UPDATE table_count SET count='$val' WHERE name='$key'", $this->xlink);
			return $val;
		}
	}
	
	public function truncate($table) {
		$table = $this->tablepre.$table;
		try {
			$this->query("TRUNCATE $table");// 不存在，会报错，但无关紧要
			return TRUE;
		} catch(Exception $e) {
			return FALSE;
		}
	}

	// -------------> 公共方法，非公开接口
	public function fetch_first($sql, $link = NULL) {
		empty($link) && $link = $this->rlink;
		$result = $this->query($link, $sql);
		return mysqli_fetch_assoc($result);
	}
	
	public function query($sql, $multi_row_flag = true) {
		var_dump($sql);
		var_dump($this->rlink);
		$res = mysqli_query($this->rlink, $sql);
		if(!$res) {
			throw new Exception(self::br('MySQL Query Error:'.$sql.'. '.mysqli_error($link)));
		}

		var_dump($res);

		if($multi_row_flag)
			return mysqli_fetch_all($res);
		else
			return mysqli_fetch_assoc($res);
		
	}
	
	public function connect($host, $user, $password, $name, $charset = '', $engine = '') {
		$link = new mysqli($host, $user, $password, $name);
		if(!$link) {
			throw new Exception(self::br(mysqli_error($link)));
		}
		$bool = mysqli_select_db($link,$name);
		if(!$bool) {
			throw new Exception(self::br(mysqli_error($link)));
		}
		if(!empty($engine) && $engine == 'InnoDB') {
			$this->query("SET innodb_flush_log_at_trx_commit=no", $link);
		}
		return $link;
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

	// private function result($query, $row) {
	// 	return mysqli_num_rows($query) ? intval($query->fetch_row()) : 0;
	// }
	
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
		$key = addslashes($key);
		$count = 0;
		$query = mysqli_query($this->xlink, "SELECT count FROM table_count WHERE name='$key' limit 1");
		if($query) {
			$res = $query->fetch_assoc();
			$count = $res['count'];
		} elseif(mysqli_errno($this->xlink) == 0) {
			$this->query("CREATE TABLE table_count (
				`name` char(32) NOT NULL default '',
				`count` int(11) unsigned NOT NULL default '0',
				PRIMARY KEY (`name`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci", $this->xlink);
		} else {
			throw new Exception('table_cout 错误, mysqli_error:'.mysqli_error($this->xlink));
		}
		if(empty($count)) {
			$this->query("REPLACE INTO table_count SET name='$key', count='0'", $this->xlink);
		}
		return $count;
	}
	
	/*
		例子：只能为表名
		table_maxid('forum-fid');
		table_maxid('thread-tid');
	*/
	private function table_maxid($key) {
		$key = addslashes($key);
		list($table, $col) = explode('-', $key);
		$maxid = 0;
		$query = mysqli_query("SELECT maxid FROM table_maxid WHERE name='$table'", $this->xlink);
		if($query) {
			$maxid = $this->result($query, 0);
		} elseif(mysqli_errno($this->xlink) == 1146) {
			$this->query("CREATE TABLE `table_maxid` (
				`name` char(32) NOT NULL default '',
				`maxid` int(11) unsigned NOT NULL default '0',
				PRIMARY KEY (`name`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci", $this->xlink);
		} else {
			throw new Exception("table_maxid 错误, mysql_errno:".mysqli_errno($link).', mysql_error:'.mysqli_error($link));
		}
		if(empty($maxid)) {
			$query = $this->query("SELECT MAX($col) FROM $table", $this->xlink);
			$maxid = $this->result($query, 0);
			$this->query("REPLACE INTO table_maxid SET name='$table', maxid='$maxid'", $this->xlink);
		}
		return $maxid;
	}
	
	public static function br($s) {
		if(!core::is_cmd()) {
			return nl2br($s);
		} else {
			return $s;
		}
	}
	
	/*
		in: 'forum-fid-1-uid-2'
		out: array('forum', 'fid=1 AND uid=2', array('fid'=>1, 'uid'=>2))
	*/
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
	
	// 最好能保证它能最后析构!
	public function __destruct() {
		if(!empty($this->wlink)) {
			mysqli_close($this->wlink);
		}
		if(!empty($this->rlink) && !empty($this->wlink) && $this->rlink != $this->wlink) {
			mysqli_close($this->rlink);
		}
	}
	
	public function version() {
		return mysql_get_server_info($this->rlink);
	}

}
?>