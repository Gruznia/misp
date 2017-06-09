<?php

class SyncTool {
	// take a server as parameter and return a HttpSocket object using the ssl options defined in the server settings
	public function setupHttpSocket($server = null) {
		$params = array();
		App::uses('HttpSocket', 'Network/Http');
		if (!empty($server)) {
			if ($server['Server']['cert_file']) $params['ssl_cafile'] = APP . "files" . DS . "certs" . DS . $server['Server']['id'] . '.pem';
			if ($server['Server']['client_cert_file']) $params['ssl_local_cert'] = APP . "files" . DS . "certs" . DS . $server['Server']['id'] . '_client.pem';
			if ($server['Server']['self_signed']) $params['ssl_allow_self_signed'] = $server['Server']['self_signed'];
		}
		$HttpSocket = new HttpSocket($params);

		$proxy = Configure::read('Proxy');
		if (isset($proxy['host']) && !empty($proxy['host']) && !$server['Server']['disable_proxy']) $HttpSocket->configProxy($proxy['host'], $proxy['port'], $proxy['method'], $proxy['user'], $proxy['password']);
		return $HttpSocket;
	}

	public function setupHttpSocketFeed($feed = null) {
		App::uses('HttpSocket', 'Network/Http');
		$HttpSocket = new HttpSocket();
		$proxy = Configure::read('Proxy');
		if (isset($proxy['host']) && !empty($proxy['host']) && !$feed['Feed']['disable_proxy']) $HttpSocket->configProxy($proxy['host'], $proxy['port'], $proxy['method'], $proxy['user'], $proxy['password']);
		return $HttpSocket;
	}
}
