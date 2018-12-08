<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
include_once('include/SSDB.php');

if (!function_exists('http_response_code')) {
	function http_response_code($code = NULL) {
		if ($code !== NULL) {
			switch ($code) {
			case 100: $text = 'Continue'; break;
			case 101: $text = 'Switching Protocols'; break;
			case 200: $text = 'OK'; break;
			case 201: $text = 'Created'; break;
			case 202: $text = 'Accepted'; break;
			case 203: $text = 'Non-Authoritative Information'; break;
			case 204: $text = 'No Content'; break;
			case 205: $text = 'Reset Content'; break;
			case 206: $text = 'Partial Content'; break;
			case 300: $text = 'Multiple Choices'; break;
			case 301: $text = 'Moved Permanently'; break;
			case 302: $text = 'Moved Temporarily'; break;
			case 303: $text = 'See Other'; break;
			case 304: $text = 'Not Modified'; break;
			case 305: $text = 'Use Proxy'; break;
			case 400: $text = 'Bad Request'; break;
			case 401: $text = 'Unauthorized'; break;
			case 402: $text = 'Payment Required'; break;
			case 403: $text = 'Forbidden'; break;
			case 404: $text = 'Not Found'; break;
			case 405: $text = 'Method Not Allowed'; break;
			case 406: $text = 'Not Acceptable'; break;
			case 407: $text = 'Proxy Authentication Required'; break;
			case 408: $text = 'Request Time-out'; break;
			case 409: $text = 'Conflict'; break;
			case 410: $text = 'Gone'; break;
			case 411: $text = 'Length Required'; break;
			case 412: $text = 'Precondition Failed'; break;
			case 413: $text = 'Request Entity Too Large'; break;
			case 414: $text = 'Request-URI Too Large'; break;
			case 415: $text = 'Unsupported Media Type'; break;
			case 500: $text = 'Internal Server Error'; break;
			case 501: $text = 'Not Implemented'; break;
			case 502: $text = 'Bad Gateway'; break;
			case 503: $text = 'Service Unavailable'; break;
			case 504: $text = 'Gateway Time-out'; break;
			case 505: $text = 'HTTP Version not supported'; break;
			default: exit('Unknown http status code "' . htmlentities($code) . '"'); break;
			}
			$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
			header($protocol . ' ' . $code . ' ' . $text);
			$GLOBALS['http_response_code'] = $code;
		} else {
			$code = (isset($GLOBALS['http_response_code']) ? $GLOBALS['http_response_code'] : 200);
		}
		return $code;
	}
}
function sizeFilter( $bytes )
{
	$label = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
	for( $i = 0; $bytes >= 1024 && $i < ( count( $label ) -1 ); $bytes /= 1024, $i++ );
	return( number_format( $bytes, 2, '.', '' ) . " " . $label[$i] );
}
try{
	$lang = 0;
	if( isset( $_REQUEST['lang'] ) ) {
		$lang = intval($_REQUEST['lang']);
	}

	$ssdb = new SimpleSSDB('localhost', 8888);
	$count = $ssdb->dbsize();

	$m = new MongoClient();
	$dbs = $m->listDBs();
	$count1 = 0;
	foreach($dbs['databases'] as $db) {
		if($db['name'] == 'cdbqueue')
			$count1 += $db['sizeOnDisk'];
		else if($db['name'] == 'cdbsel')
			$count1 += $db['sizeOnDisk'];
	}

	$collection = $m->selectDB('cdbqueue')->selectCollection('queuedb');
	$cursor = $collection->find();
	$count2 = $cursor->count();
	$cursor->reset();

	$collection = $m->selectDB('cdbsel')->selectCollection('seldb');
	$cursor = $collection->find();
	$count3 = $cursor->count();
	$cursor->reset();

	$ppm = 0;
	$nps = 0;
	$memcache_obj = new Memcache();
	$memcache_obj->pconnect('localhost', 11211);
	if( !$memcache_obj )
		throw new Exception( 'Memcache error.' );

	$activelist = $memcache_obj->get('WorkerList2');
	if($activelist !== FALSE) {
		$lastminute = date('i', time() - 60);
		foreach($activelist as $key => $value) {
			$ppn = $memcache_obj->get('Worker2::' . $key . 'PC_' . $lastminute);
			if( $ppn !== FALSE ) {
				$ppm += $ppn;
			}
			$npn = $memcache_obj->get('Worker2::' . $key . 'NC_' . $lastminute);
			if( $npn !== FALSE ) {
				$nps += $npn;
			}
		}
		$nps /= 60 * 1000 * 1000;
	}
	echo '<table class="stats">';
	if($lang == 0) {
		echo '<tr><td>数据体积（常规 / 队列）：</td><td style="text-align: right;">' . sizeFilter( $count ) . ' / ' . sizeFilter( $count1 ) . '</td></tr>';
		echo '<tr><td>后台队列（评估 / 学习）：</td><td style="text-align: right;">' . number_format( $count2 ) . ' / ' . number_format( $count3 ) . '</td></tr>';
		echo '<tr><td>计算速度（局面 / 分钟）：</td><td style="text-align: right;">' . number_format( $ppm ) . ' @ ' . number_format( $nps, 3, '.', '' ) . ' GNPS</td></tr>';
	} else {
		echo '<tr><td>DB Size ( Storage / Queue ) :</td><td style="text-align: right;">' . sizeFilter( $count ) . ' / ' . sizeFilter( $count1 ) . '</td></tr>';
		echo '<tr><td>Queue ( Scoring / Learning ) :</td><td style="text-align: right;">' . number_format( $count2 ) . ' / ' . number_format( $count3 ) . '</td></tr>';
		echo '<tr><td>Speed ( Position / Minute ) :</td><td style="text-align: right;">' . number_format( $ppm ) . ' @ ' . number_format( $nps, 3, '.', '' ) . ' GNPS</td></tr>';
	}
	echo '</table>';
}
catch (MongoException $e) {
	echo 'Error: ' . $e->getMessage();
	http_response_code(503);
}
catch (SSDBException $e) {
	echo 'Error: ' . $e->getMessage();
	http_response_code(503);
}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
	http_response_code(503);
}

