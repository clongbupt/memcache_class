<?php
	
	header( 'Content-Type:text/html; charset=utf-8 ');

	include_once('../appFunction.php');

	// youku test
	// $url = "http://v.youku.com/v_show/id_XMzI4ODIwOTky.html";
	// $video_time = get_video_time($url,1);

	// var_dump("youku");
	// var_dump($video_time);

	// // tudou test
	// $url = "http://www.tudou.com/programs/view/G6qDaGUJOy4/";
	// $video_time = get_video_time($url);

	// echo "<br />";

	// var_dump("tudou");
	// var_dump($video_time);


	// // 56 test
	// $url = "http://www.56.com/u89/v_OTIwNjYwMzA.html";
	// $video_time = get_video_time($url);

	// echo "<br />";

	// var_dump("56");
	// var_dump($video_time);


	// // sina test
	// $url = "http://video.sina.com.cn/p/sports/k/v/2012-02-17/093061668231.html";
	// $video_time = get_video_time($url);

	// echo "<br />";

	// var_dump("sina");
	// var_dump($video_time);


	// // ku6 test
	// $url = "http://v.ku6.com/show/m_DQgeVd2PlvNcHlm6Maog...html";
	// $video_time = get_video_time($url);

	// echo "<br />";

	// var_dump("ku6");
	// var_dump($video_time);

	// // iqiyi test
	// $url = "http://www.iqiyi.com/ad/20130116/b03d39a731df3ad2.html";
	// $video_time = get_video_time($url);

	// echo "<br />";

	// var_dump("iqiyi");
	// var_dump($video_time);

	// // sohu test
	// $url = "http://tv.sohu.com/20120313/n337578340.shtml";
	// $video_time = get_video_time($url);

	// echo "<br />";

	// var_dump("sohu");
	// var_dump($video_time);

	// // qq test
	// $url = "http://v.qq.com/boke/page/b/g/4/b01010zn8g4.html";
	// $video_time = get_video_time($url);

	// echo "<br />";

	// var_dump("qq");
	// var_dump($video_time);
	 
	 
	
	include '../db/model.php';

	// 读取conf文件, 然后通过db直接搞定
	$model = new Model();

	$sql = "select * from app_posts limit 1";
	$row = $model->query(array($sql,"app_posts"),false);

	echo "<pre>";
	print_r($row);
	echo "</pre>";
	
?>