<?php

class Kohana_MultiRequest {

	/**
	 * @var resource curl_init
	 */
	private $ch;
	/**
	 * @var resource curl_multi_init
	 */
	private $cm;
	private $channels = [];

	public function __construct($options = [])
	{
		$this->ch = curl_init();
		$this->cm = curl_multi_init();

		curl_setopt_array($this->ch, $options);
	}

	public function __destruct()
	{
		curl_multi_close($this->cm);
		curl_close($this->ch);
	}


	public function add(Request $request)
	{
		/**
		 * @var $client Request_Client_External
		 */
		$client = $request->client();

		if ( ! ($client instanceof Request_Client_Curl))
		{
			throw new Request_Exception('Request client must be Request_Client_Curl');
		}

		$copy_handle = curl_copy_handle($this->ch);
//		$copy_handle = curl_init();

		curl_setopt_array($copy_handle, $client->options());

		if ( ! UTF8::is_ascii($request->uri()))
		{
			$request->uri(curl_escape($copy_handle, $request->uri()));
		}

		curl_setopt($copy_handle, CURLOPT_URL, $request->uri());

		if (curl_multi_add_handle($this->cm, $copy_handle) !== 0)
		{
			// Доступно с PHP 7 >= 7.1.0
			throw new Request_Exception(curl_multi_errno($this->cm));
		}

		$this->channels[] = $copy_handle;

		// :TODO: закрытие дескриптора?
	}

	public function execute()
	{
		// execute - if there is an active connection then keep looping
//		$active = NULL;

		/*do
		{
			$status = curl_multi_exec($this->cm, $active);
		}
		while ($active AND $status == CURLM_OK);*/

		do
		{
			curl_multi_exec($this->cm, $running);
			curl_multi_select($this->cm);
		}
		while ($running > 0);

		// echo the content, remove the handlers, then close them
		foreach ($this->channels as $chan)
		{
			Minion_CLI::write(substr(curl_multi_getcontent($chan), 0, 50));
			curl_multi_remove_handle($this->cm, $chan);
			curl_close($chan);
		}
	}
/*
	private function _send_message(Request $request, $curl)
	{
		// Response headers
		$response_headers = array();

		$options = array();

		// Set the request method
		$options = $this->_set_curl_request_method($request, $options);

		// Set the request body. This is perfectly legal in CURL even
		// if using a request other than POST. PUT does support this method
		// and DOES NOT require writing data to disk before putting it, if
		// reading the PHP docs you may have got that impression. SdF
		// This will also add a Content-Type: application/x-www-form-urlencoded header unless you override it
		if ($body = $request->body()) {
			$options[CURLOPT_POSTFIELDS] = $body;
		}

		// Process headers
		if ($headers = $request->headers())
		{
			$http_headers = array();

			foreach ($headers as $key => $value)
			{
				$http_headers[] = $key.': '.$value;
			}

			$options[CURLOPT_HTTPHEADER] = $http_headers;
		}

		// Process cookies
		if ($cookies = $request->cookie())
		{
			$options[CURLOPT_COOKIE] = http_build_query($cookies, NULL, '; ');
		}

		// Get any exisiting response headers
		$response_header = $response->headers();

		// Implement the standard parsing parameters
		$options[CURLOPT_HEADERFUNCTION]        = array($response_header, 'parse_header_string');

		$client_options = $request->client()->options();
		$client_options[CURLOPT_RETURNTRANSFER] = TRUE;
		$client_options[CURLOPT_HEADER]         = FALSE;

		// Apply any additional options set to
		$options += $client_options;

		$uri = $request->uri();

		if ($query = $request->query())
		{
			$uri .= '?'.http_build_query($query, NULL, '&');
		}

		// Open a new remote connection
//		$curl = curl_init($uri);

		// Set connection options
		if ( ! curl_setopt_array($curl, $options))
		{
			throw new Request_Exception('Failed to set CURL options, check CURL documentation: :url',
				array(':url' => 'http://php.net/curl_setopt_array'));
		}

		// Get the response body
//		$body = curl_exec($curl);

		// Get the response information
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		if ($body === FALSE)
		{
			$error = curl_error($curl);
		}

		// Close the connection
//		curl_close($curl);

		if (isset($error))
		{
			throw new Request_Exception('Error fetching remote :url [ status :code ] :error',
				array(':url' => $request->url(), ':code' => $code, ':error' => $error));
		}

		$response->status($code)->body($body);

		return $curl;
	}*/

	/*private function _set_curl_request_method(Request $request, array $options)
	{
		switch ($request->method()) {
			case Request::POST:
				$options[CURLOPT_POST] = TRUE;
				break;
			default:
				$options[CURLOPT_CUSTOMREQUEST] = $request->method();
				break;
		}
		return $options;
	}*/
}