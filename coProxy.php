<?php
class coProxy {
  private $__url = NULL;
  private $__proxy_url = NULL;
  private $__proxy_host = NULL;
  private $__proxy_proto = NULL;
  private $__translated_url = NULL;
  private $__curl_handler = NULL;
  private $__cache_control = false;
  private $__pragma = false;
  private $__client_headers = array();
  private $__timeout = 5;

  public function __construct( $url = NULL, $proxy_url = NULL, $timeout = 5 ) {
    // Strip the trailing '/' from the URLs so they are the same.
    $this->__url = rtrim( $url, '/' );
    $this->__proxy_url = rtrim( $proxy_url, '/' );
    $this->__timeout = $timeout;
    // Parse all the parameters for the URL
    if( isset( $_SERVER['PATH_INFO'] ) ) {
      $proxy_url .= $_SERVER['PATH_INFO'];
    }
    else {
      // Add the '/' at the end
      $proxy_url .= '/';
    }
    if( $_SERVER['QUERY_STRING'] !== '' ) {
      $proxy_url .= "?{$_SERVER['QUERY_STRING']}";
    }

    $this->__translated_url = $proxy_url;
    $this->__curl_handler = curl_init( $this->__translated_url );
    $this->_setCurlOption( CURLOPT_RETURNTRANSFER, false );
    $this->_setCurlOption( CURLOPT_BINARYTRANSFER, true ); // For images, etc.
    $this->_setCurlOption( CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT'] );
    $this->_setCurlOption( CURLOPT_WRITEFUNCTION, array( $this, '_readResponse' ) );
    $this->_setCurlOption( CURLOPT_HEADERFUNCTION, array( $this, '_readHeaders' ) );
    $this->_setCurlOption( CURLOPT_TIMEOUT, $this->__timeout );

    // Process post data.
    if( count( $_POST ) ) {
      $post = array();
      // Set the post data
      $this->_setCurlOption( CURLOPT_POST, true );
      // Encode and form the post data
      foreach( $_POST as $key => $value ) {
         $post[] = urlencode( $key ) . "=" . urlencode( $value );
      }
      $this->_setCurlOption( CURLOPT_POSTFIELDS, implode( '&', $post ) );
      unset( $post );
    }
    elseif( $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
      // Default request method is 'get
      $this->_setCurlOption( CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD'] );
    }
    // Handle the client headers.
    $this->_handleClientHeaders();
  } // end __construct

  
  // Sets a curl option.
  protected function _setCurlOption( $option = NULL, $value = NULL ) {
    curl_setopt( $this->__curl_handler, $option, $value );
  }


  protected function _readHeaders( &$cu = NULL, $string = NULL ) {
    $length = strlen( $string );
    if( preg_match( ',^Location:,', $string ) ) {
      $string = str_replace( $this->__proxy_url, $this->__url, $string );
    }
    elseif( preg_match( ',^Cache-Control:,', $string ) ) {
      $this->__cache_control = true;
    }
    elseif( preg_match( ',^Pragma:,', $string ) ) {
      $this->__pragma = true;
    }
    if( $string !== "\r\n" ) {
      header( rtrim( $string ) );
    }
    return $length;
  }
    

  protected function _readResponse( &$cu = NULL, $string = NULL ) {
    static $headersParsed = false;
    // Clear the Cache-Control and Pragma headers
    // if they aren't passed from the proxy application.
    if( $headersParsed === false ) {
      if( !$this->__cache_control ) header( 'Cache-Control: ' );

      if( !$this->pragma ) header( 'Pragma: ' );

      $headersParsed = true;
    }
    $length = strlen($string);
    echo $string;
    return $length;
  }


  protected function _requestHeaders() {
    if( function_exists( 'apache_request_headers' ) ) {
      return apache_request_headers();
    }
    $headers = array();
    foreach( $_SERVER as $k => $v ) {
      if( substr( $k, 0, 5 ) == "HTTP_" ) {
        $k = str_replace( '_', ' ', substr( $k, 5 ) );
        $k = str_replace( ' ', '-', ucwords( strtolower( $k ) ) );
        $headers[$k] = $v;
      }
    }
    return $headers;
  } 


  protected function _setClientHeader( $header = NULL ) {
    $this->__client_headers[] = $header;
  }


  protected function _handleClientHeaders() {
    $headers = $this->_requestHeaders();
    foreach( $headers as $header => $value ) {
      switch( $header ) {
        case 'Host':
          break;
        default:
          $this->_setClientHeader( sprintf( '%s: %s', $header, $value ) );
          break;
      }
    }
    $proxy  = $_SERVER['SERVER_ADDR'];
    $client = $_SERVER['REMOTE_ADDR'];
    $this->_setClientHeader( 'X-coProxy-Forwarded-Via: ' . $proxy );
    $this->_setClientHeader( 'X-coProxy-Forwarded-For: ' . $client );
  }

  
  protected function _getContent( $url = NULL ) {
    $ch = curl_init();        
    curl_setopt( $ch, CURLOPT_HEADER, false );
    curl_setopt( $ch, CURLOPT_TIMEOUT, $this->__timeout );
    //Set curl to return the data instead of printing it to the browser.
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_URL, $url );
    $data = curl_exec( $ch );
    curl_close( $ch );
    return $data;
  }


  public function run() {
    $this->_setCurlOption( CURLOPT_HTTPHEADER, $this->__client_headers );
    curl_exec( $this->__curl_handler );
  }


  public function valid( $flag = 'proxy', $curl = true, $timeout = 5 ) {
    $url = $this->__proxy_url . '/' . $flag;  
    if( $curl ) {
      $ret = substr( trim( $this->_getContent( $url, $timeout ) ), 0, 4 );
    }
    else {
      $scc = stream_context_create( array( 'http' => array( 'timeout' => $timeout ) ) );
      $ret = substr( trim( file_get_contents( $url, 0, $scc ) ), 0, 4 );
    }
    if( strtoupper( $ret ) == "TRUE" ) return true;

    return false;
  }

} // end class coProxy

?>
