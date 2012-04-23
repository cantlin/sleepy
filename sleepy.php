<?php

namespace Sleepy;

class Conf {
	public static $base_dir = 'sleepy';
	public static $collection_aliases = [];
}

class DispatchException extends \Exception {}
class NotImplementedException extends \Exception {}

abstract class Singleton {
	private static $instances = [];

	final public static function getInstance() {
		$class = get_called_class();

		if(!isset(self::$instances[$class])) {
			self::$instances[$class] = new $class;
		}

		return self::$instances[$class];
	}
}

class Request extends Singleton {
	public $uri, $http_method, $headers, $content_type, $format, $params, $formats;

	public function __construct() {
		$uri_sections = explode('?', $_SERVER['REQUEST_URI']);
		$uri = $uri_sections[0];
		$query_string = isset($uri_sections[1]) ? $uri_sections[1] : '';

		$uri_parts = explode('/', $uri);
		foreach($uri_parts as $k => $part) {
			if(empty($part) || $part == Conf::$base_dir) {
				unset($uri_parts[$k]);
			}
		}

		/*
		 * Ignoring Accept and Content-Type headers for the time being
		 * and returning JSON for everything. Sadface.

		$accept_formats = explode(',', $_SERVER['HTTP_ACCEPT']);
		$this->formats = array_map(function($content_type) {
			$format_parts = explode('/', $content_type);
			return $format_parts[1];
		}, $accept_formats);

		$this->accept   = $accept_formats[0];
		$format_parts   = explode('/', $this->accept);
		$this->format   = ($format_parts[1] == '*') ? 'json' : $format_parts[1];

		*/
		$this->format = 'json';

		$this->http_method = $_SERVER['REQUEST_METHOD'];
		foreach(apache_request_headers() as $key => $value) {
			$this->headers[strtolower($key)] = $value;
		}
		$this->raw_uri = $_SERVER['REQUEST_URI'];
		$this->uri = array_values($uri_parts);
		parse_str($query_string, $this->params);
		$this->params = array_merge($this->params, $_POST);
		if(!empty($_FILES)) {
			$this->params['files'] = $_FILES;
		}
	}

	public function getHeader($key) {
		return isset($this->headers[strtolower($key)]) ? $this->headers[strtolower($key)] : false;
	}
}

class Response extends Singleton {
	public $status, $content_type, $body;

	public function deliver() {
		$response = self::getInstance();
		$messages = self::$status_messages;
		header('HTTP/1.1 ' . $response->status . ' ' . $messages[$response->status]);

		switch($response->format) {
			case 'json':
				$content_type = 'application/json';
				break;
			case 'xml':
				$content_type = 'application/xml';
				break;
			case 'html':
				$content_type = 'text/html';
		}
		header('Content-Type: ' . $content_type . '; charset=utf-8');

		echo $response->body;
	}

	public static $status_messages = array(
	    100 => 'Continue',
	    101 => 'Switching Protocols',
	    200 => 'OK',
	    201 => 'Created',
	    202 => 'Accepted',
	    203 => 'Non-Authoritative Information',
	    204 => 'No Content',
	    205 => 'Reset Content',
	    206 => 'Partial Content',
	    300 => 'Multiple Choices',
	    301 => 'Moved Permanently',
	    302 => 'Found',  // 1.1
	    303 => 'See Other',
	    304 => 'Not Modified',
	    305 => 'Use Proxy',
	    307 => 'Temporary Redirect',
	    400 => 'Bad Request',
	    401 => 'Unauthorized',
	    402 => 'Payment Required',
	    403 => 'Forbidden',
	    404 => 'Not Found',
	    405 => 'Method Not Allowed',
	    406 => 'Not Acceptable',
	    407 => 'Proxy Authentication Required',
	    408 => 'Request Timeout',
	    409 => 'Conflict',
	    410 => 'Gone',
	    411 => 'Length Required',
	    412 => 'Precondition Failed',
	    413 => 'Request Entity Too Large',
	    414 => 'Request-URI Too Long',
	    415 => 'Unsupported Media Type',
	    416 => 'Requested Range Not Satisfiable',
	    417 => 'Expectation Failed',
	    500 => 'Internal Server Error',
	    501 => 'Not Implemented',
	    502 => 'Bad Gateway',
	    503 => 'Service Unavailable',
	    504 => 'Gateway Timeout',
	    505 => 'HTTP Version Not Supported',
	    509 => 'Bandwidth Limit Exceeded'
	);
}

class Dispatcher {
	/* 
	 * Try and derive a Controller method from the URI without requiring routes to be manually defined.
	 * Can't see this making it to v0.1.
	 */
	static function dispatch()	{
		$request = \Sleepy\Request::getInstance();
 		$uri_parts = $request->uri;
		$class = array_shift($uri_parts);

		switch($request->http_method) {
			case 'GET':
				if(empty($uri_parts)) {
					/*
					 * GET requests with just one URI component (the class name) are assumed to ask for a
					 * collection, e.g. FooController::getMany().
					 */
					$method = 'getMany';
					if(isset(Conf::$collection_aliases[$class])) {
						$class = Conf::$collection_aliases[$class];
					} else {
						if(substr($class, -1) == 's') {
							$class = substr($class, 0, -1);
						}
					}
				} else {
					/*
					 * Other requests are assumed to have a resource identifier as the next component.
					 */
					$method = 'getOne';
					if(!$id = array_shift($uri_parts)) {
						throw new DispatchException('No resource identifier supplied.');
					}
				}
				break;

			case 'POST':
				if(isset($_POST['_method']) && $_POST['_method'] == 'PUT' || $request->getHeader('X-Sleepy-Request-Method') == 'PUT') {
					$method = 'update';
					unset($_POST['_method']);
					if(!$id = array_shift($uri_parts)) {
						throw new DispatchException('No resource identifier supplied.');
					}	
				} else {
					$method = 'create';
				}
				break;

			case 'PUT':
				$method = 'update';
				if(!$id = array_shift($uri_parts)) {
					throw new DispatchException('No resource identifier supplied.');
				}
				break;

			case 'DELETE':
				$method = 'delete';
				if(!$id = array_shift($uri_parts)) {
					throw new DispatchException('No resource identifier supplied.');
				}

		}

		$class = ucfirst($class);
		$fq_class_name = "Sleepy\\Controllers\\" . $class . "Controller";

		if(!class_exists($fq_class_name)) {
			throw new DispatchException($class . "Controller is not defined.");
		}

		if(!method_exists($fq_class_name, $method)) {
			throw new DispatchException($class . "Controller does not implement `$method`");
		}

		$params = isset($id) ? array_merge($request->params, ['id' => $id]) : $request->params;

		if(self::run_before_methods($fq_class_name, $method, $params)) {
			/* Dispatch! */
			call_user_func([$fq_class_name, $method], $params);
		}
		self::run_after_methods($fq_class_name, $method, $params);
	}

	private static function run_before_methods($class, $action, $params) {
		if(!property_exists($class, 'before')) {
			return true;
		}

		foreach($class::$before as $callback_method => $triggers) {
			if((is_bool($triggers) && $triggers) || in_array($action, $triggers)) {
				if(!method_exists($class, $callback_method)) {
					throw new DispatchException("$class does not define method `$callback_method`");
				}
				$result = call_user_func(array($class, $callback_method), $params);
				if(!$result && !is_null($result)) {
					return false;
				}
			}
		}

		return true;
	}

	private static function run_after_methods($class, $action, $params) {
		if(!property_exists($class, 'after')) {
			return;
		}

		foreach($class::$after as $callback_method => $triggers) {
			if(in_array($action, $triggers)) {
				call_user_func(array($class, $callback_method), $params);
			}
		}
	}
}

namespace Sleepy\Controllers;

interface RestInterface {
	public static function getOne($params);
	public static function getMany($params);
	public static function create($params);
	public static function update($params);
	public static function delete($params);
}

class BaseController {}

function render($payload, $status_code = 200) {
	$body = '';

	if(is_array($payload)) {
		$body = json_encode($payload);
	}

	if(is_object($payload)) {
		if($payload instanceof \ArrayObject) {
			$arr = [];
			foreach($payload as $item) {
				if(is_object($item)) {
					$arr[] = method_exists($item, 'export') ? $item->export('stub') : $item->toArray();
				} else {
					$arr[] = $item;
				}
			}
			$body = json_encode($arr);
		} else {
			$export = method_exists($payload, 'export') ? $payload->export() : $payload->toArray();
			$body   = json_encode($export);
		}
	}

	if(is_string($payload)) {
		$body = $payload;
	}

	$response = \Sleepy\Response::getInstance();
	$response->status = $status_code;
	$response->body = empty($body) ? \Sleepy\Response::$status_messages[$status_code] : $body;
	$response->format = 'json';

	return true;
}

function head($status) {
	return render(null, $status);
}

function error($payload, $status = 400) {
	$payload = is_array($payload) ? $payload : [ 'error' => $payload ];
	return render($payload, $status);
}

?>