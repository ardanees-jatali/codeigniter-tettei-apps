<?php
/**
 * Part of CI PHPUnit Test
 *
 * @author     Kenji Suzuki <https://github.com/kenjis>
 * @license    MIT License
 * @copyright  2015 Kenji Suzuki
 * @link       https://github.com/kenjis/ci-phpunit-test
 */

class CIPHPUnitTestRequest
{
	protected $testCase;

	/**
	 * @var CIPHPUnitTestSuperGlobal
	 */
	protected $superGlobal;

	/**
	 * @var callable callable post controller constructor
	 */
	protected $callable;
	
	/**
	 * @var callable callable pre controller constructor
	 */
	protected $callablePreConstructor;

	protected $enableHooks = false;
	protected $CI;
	
	/**
	 * @var CI_Hooks
	 */
	protected $hooks;

	/**
	 * @var bool whether throwing PHPUnit_Framework_Exception or not
	 * 
	 * If true, throws PHPUnit_Framework_Exception when show_404() and show_error() are called. This behavior is compatible to v0.3.0 and before.
	 * 
	 * @deprecated
	 */
	protected $bc_mode_throw_PHPUnit_Framework_Exception = false;

	public function __construct(PHPUnit_Framework_TestCase $testCase)
	{
		$this->testCase = $testCase;
		$this->superGlobal = new CIPHPUnitTestSuperGlobal();
	}

	/**
	 * Set HTTP request header
	 * 
	 * @param string $name  header name
	 * @param string $value value
	 */
	public function setHeader($name, $value)
	{
		$this->superGlobal->set_SERVER_HttpHeader($name, $value);
	}

	/**
	 * Set callable
	 * 
	 * @param callable $callable function to run after controller instantiation
	 */
	public function setCallable(callable $callable)
	{
		$this->callable = $callable;
	}

	/**
	 * Set callable pre constructor
	 * 
	 * @param callable $callable function to run before controller instantiation
	 */
	public function setCallablePreConstructor(callable $callable)
	{
		$this->callablePreConstructor = $callable;
	}

	/**
	 * Enable Hooks for Controllres
	 * This enables only pre_controller, post_controller_constructor, post_controller
	 */
	public function enableHooks()
	{
		$this->enableHooks = true;
		$this->hooks =& load_class('Hooks', 'core');
	}

	/**
	 * Request to Controller
	 *
	 * @param string       $http_method HTTP method
	 * @param array|string $argv        array of controller,method,arg|uri
	 * @param array|string $params      POST params/GET params|raw_input_stream
	 */
	public function request($http_method, $argv, $params = [])
	{
		// We need this because if 404 route, no controller is created.
		// But we need $this->CI->output->_status
		$this->CI =& get_instance();

		if (is_string($argv))
		{
			$argv = ltrim($argv, '/');
		}

		// Set super globals
		$_SERVER['REQUEST_METHOD'] = $http_method;
		$this->superGlobal->set_GET($argv, $params);
		$this->superGlobal->set_POST($params);
		$this->superGlobal->set_SERVER_REQUEST_URI($argv);

		try {
			if (is_array($argv))
			{
				return $this->callControllerMethod(
					$http_method, $argv, $params
				);
			}
			else
			{
				return $this->requestUri($http_method, $argv, $params);
			}
		}
		// redirect()
		catch (CIPHPUnitTestRedirectException $e)
		{
			if ($e->getCode() === 0)
			{
				set_status_header(200);
			}
			else
			{
				set_status_header($e->getCode());
			}
			$this->CI->output->_status['redirect'] = $e->getMessage();
		}
		// show_404()
		catch (CIPHPUnitTestShow404Exception $e)
		{
			$this->processError($e);
			return $e->getMessage();
		}
		// show_error()
		catch (CIPHPUnitTestShowErrorException $e)
		{
			$this->processError($e);
			return $e->getMessage();
		}
	}

	protected function processError(Exception $e)
	{
		set_status_header($e->getCode());

		// @deprecated
		if ($this->bc_mode_throw_PHPUnit_Framework_Exception)
		{
			throw new PHPUnit_Framework_Exception(
				$e->getMessage(), $e->getCode()
			);
		}
	}

	/**
	 * Call Controller Method
	 *
	 * @param string       $http_method    HTTP method
	 * @param array        $argv           controller, method [, arg1, ...]
	 * @param array|string $request_params POST params/GET params|raw_input_stream
	 */
	protected function callControllerMethod($http_method, $argv, $request_params)
	{
		$_SERVER['argv'] = array_merge(['index.php'], $argv);

		$class  = ucfirst($argv[0]);
		$method = $argv[1];

		// Remove controller and method
		array_shift($argv);
		array_shift($argv);

//		$request = [
//			'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
//			'class' => $class,
//			'method' => $method,
//			'params' => $argv,
//			'$_GET' => $_GET,
//			'$_POST' => $_POST,
//		];
//		var_dump($request, $_SERVER['argv']);

		// Reset CodeIgniter instance state
		reset_instance();

		$this->setRawInputStream($request_params);

		// 404 checking
		if (! class_exists($class) || ! method_exists($class, $method))
		{
			show_404($class.'::'.$method . '() is not found');
		}

		$params = $argv;

		return $this->createAndCallController($class, $method, $params);
	}

	/**
	 * Request to URI
	 *
	 * @param string       $http_method    HTTP method
	 * @param string       $uri            URI string
	 * @param array|string $request_params POST params/GET params|raw_input_stream
	 */
	protected function requestUri($http_method, $uri, $request_params)
	{
		$_SERVER['argv'] = ['index.php', $uri];

		// Force cli mode because if not, it changes URI (and RTR) behavior
		$cli = is_cli();
		set_is_cli(TRUE);

		// Reset CodeIgniter instance state
		reset_instance();

		$this->setRawInputStream($request_params);

		// Get route
		$RTR =& load_class('Router', 'core');
		$URI =& load_class('URI', 'core');
		list($class, $method, $params) = $this->getRoute($RTR, $URI);

		// Restore cli mode
		set_is_cli($cli);

//		$request = [
//			'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
//			'class' => $class,
//			'method' => $method,
//			'params' => $params,
//			'$_GET' => $_GET,
//			'$_POST' => $_POST,
//		];
//		var_dump($request, $_SERVER['argv']);

		return $this->createAndCallController($class, $method, $params);
	}

	protected function callHook($hook)
	{
		if ($this->enableHooks)
		{
			$this->hooks->call_hook($hook);
		}
	}

	protected function setRawInputStream($string)
	{
		if (is_string($string))
		{
			$INPUT =& load_class('Input', 'core');
			CIPHPUnitTestReflection::setPrivateProperty(
				$INPUT,
				'_raw_input_stream',
				$string
			);
		}
	}

	protected function createAndCallController($class, $method, $params)
	{
		ob_start();

		$this->callHook('pre_controller');

		// Run callablePreConstructor
		if (is_callable($this->callablePreConstructor))
		{
			$callable = $this->callablePreConstructor;
			$callable();
		}

		// Create controller
		$controller = new $class;
		$this->CI =& get_instance();

		// Set CodeIgniter instance to TestCase
		$this->testCase->setCI($this->CI);

		// Set default response code 200
		set_status_header(200);
		// Run callable
		if (is_callable($this->callable))
		{
			$callable = $this->callable;
			$callable($this->CI);
		}

		$this->callHook('post_controller_constructor');

		// Call controller method
		call_user_func_array([$controller, $method], $params);
		$output = ob_get_clean();

		if ($output == '')
		{
			$output = $this->CI->output->get_output();
		}

		$this->callHook('post_controller');

		return $output;
	}

	/**
	 * Get Route including 404 check
	 *
	 * @see core/CodeIgniter.php
	 *
	 * @param CI_Route $RTR Router object
	 * @param CI_URI   $URI URI object
	 * @return array   [class, method, pararms]
	 */
	protected function getRoute($RTR, $URI)
	{
		$e404 = FALSE;
		$class = ucfirst($RTR->class);
		$method = $RTR->method;

		if (empty($class) OR ! file_exists(APPPATH.'controllers/'.$RTR->directory.$class.'.php'))
		{
			$e404 = TRUE;
		}
		else
		{
			require_once(APPPATH.'controllers/'.$RTR->directory.$class.'.php');

			if ( ! class_exists($class, FALSE) OR $method[0] === '_' OR method_exists('CI_Controller', $method))
			{
				$e404 = TRUE;
			}
			elseif (method_exists($class, '_remap'))
			{
				$params = array($method, array_slice($URI->rsegments, 2));
				$method = '_remap';
			}
			// WARNING: It appears that there are issues with is_callable() even in PHP 5.2!
			// Furthermore, there are bug reports and feature/change requests related to it
			// that make it unreliable to use in this context. Please, DO NOT change this
			// work-around until a better alternative is available.
			elseif ( ! in_array(strtolower($method), array_map('strtolower', get_class_methods($class)), TRUE))
			{
				$e404 = TRUE;
			}
		}

		if ($e404)
		{
			show_404($RTR->directory.$class.'/'.$method.' is not found');
		}

		if ($method !== '_remap')
		{
			$params = array_slice($URI->rsegments, 2);
		}

		return [$class, $method, $params];
	}

	/**
	 * Get HTTP Status Code Info
	 * 
	 * @return array ['code' => code, 'text' => text]
	 * @throws LogicException
	 */
	public function getStatus()
	{
		if (! isset($this->CI->output->_status))
		{
			throw new LogicException('Status code is not set. You must call $this->request() first');
		}

		return $this->CI->output->_status;
	}
}
