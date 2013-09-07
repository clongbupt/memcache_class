<?php
return array (
	
	// 数据库配置
	'db' => array (
		'type' => 'mysql',
		'mysql' => array (
			'master' => array (
				'host' => 'localhost',
				'user' => 'root',
				'password' => 'CHEN2013vmovierH',
				'name' => 'vincent311',
				'charset' => 'utf8',
				// 'tablepre' => 'bbs_',
				'engine'=>'MyISAM',
			)
		)

	),
	// memcahe配置
	'cache' => array (
		'enable'=>1,
		'type'=>'memcache',
		'memcache'=>array (
			'multi'=>0,
			// 'host'=>'127.0.0.1',
			'host'=>'10.108.5.248',
			'port'=>'11211',
		)
	),
);
?>