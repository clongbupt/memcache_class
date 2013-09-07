<?php
	include '../db/model.php';

	// 读取conf文件, 然后通过db直接搞定
	$model = new Model();

	$sql1 = "select * from app_posts where post_id=144";
	$result1 = $model->query_new($sql1, false);

	$sql2 = "select * from app_posts where post_id=147";
	$result2 = $model->query_new($sql2, false);

	$key1 = $model->generate_key($sql1);
	$result_cache = $model->get_cache_instance()->get($key1);

	$key2 = $model->get_cache_instance()->get("app_posts");

	p("sql1", $sql1, __LINE__);
	p("key1", $key1, __LINE__);
	p("result1", $result1, __LINE__);
	p("sql2", $sql2, __LINE__);
	p("result2", $result2, __LINE__);
	p("result_cache", $result_cache, __LINE__);

	p("key2", $key2, __LINE__);

	$sql3 = "update app_posts set star = 4.5 where post_id = 144";
	$model->execute($sql3);

	$tablekey = $model->get_cache_instance()->get("app_posts");
	p("tablekey", $tablekey, __LINE__);

function p($name, $b, $line){
	$filename = basename(__FILE__);
	echo "<pre>TestCase from file : {$filename} , and print lineno  is <b>{$line}</b> <br />";
	if (is_string($b)){
		echo " {$name} : ".$b."</pre><br />";
	}else if (is_array($b)){
		echo "{$name} : ";
		print_r($b);
		echo "</pre><br />";
	}
}

?>