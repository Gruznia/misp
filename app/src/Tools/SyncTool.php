<?php

namespace Lib\Tools;

class SyncTool {
	// take a server as parameter and return a HttpSocket object using the ssl options defined in the server settings
	public function setupHttpSocket($server = null) {
		$params = array();
		if (!empty($server)) {
			if ($server['Server']['cert_file']) $params['ssl_cafile'] = APP . "files" . DS . "certs" . DS . $server['Server']['id'] . '.pem';
			if ($server['Server']['client_cert_file']) $params['ssl_local_cert'] = APP . "files" . DS . "certs" . DS . $server['Server']['id'] . '_client.pem';
			if ($server['Server']['self_signed']) $params['ssl_allow_self_signed'] = $server['Server']['self_signed'];
		}
		$HttpSocket = new HttpSocket($params);

		$proxy = Configure::read('Proxy');
		if (isset($proxy['host']) && !empty($proxy['host'])) $HttpSocket->configProxy($proxy['host'], $proxy['port'], $proxy['method'], $proxy['user'], $proxy['password']);
		return $HttpSocket;
	}

	public function setupHttpSocketFeed($feed = null) {
		$HttpSocket = new HttpSocket($params);
		$proxy = Configure::read('Proxy');
		if (isset($proxy['host']) && !empty($proxy['host'])) $HttpSocket->configProxy($proxy['host'], $proxy['port'], $proxy['method'], $proxy['user'], $proxy['password']);
		return $HttpSocket;
	}
}
