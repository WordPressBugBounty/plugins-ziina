<?php

/**
 * Logger Class
 * 
 * Static class for logging messages through the plugin's API
 */

namespace ZiinaPayment\Logger;

use ZiinaPayment\Api\Main as ApiMain;

class Main {
  /**
   * @var ApiMain Instance of the API class
   */
  private static $api = null;

  /**
   * Initialize the logger
   * 
   * @param ApiMain $api Instance of the API class (optional)
   */
  public static function init(ApiMain $api = null) {
    self::$api = $api ?: new ApiMain();
  }

  /**
   * Get API instance, create it if not exists
   * 
   * @return ApiMain
   */
  private static function getApi() {
    if (self::$api === null) {
      self::$api = new ApiMain();
    }

    return self::$api;
  }

  /**
   * Log informational messages
   * 
   * @param string $message Message to log
   * @param array $data Additional data (optional)
   * @return mixed Result of the API request
   */
  public static function info($message, array $data = []) {
    return self::logMessage('info', $message, $data);
  }

  /**
   * Log errors
   * 
   * @param string $message Error message
   * @param array $data Additional data (optional)
   * @return mixed Result of the API request
   */
  public static function error($message, array $data = []) {
    return self::logMessage('error', $message, $data);
  }

  /**
   * Log debug information
   * 
   * @param string $message Debug message
   * @param array $data Additional data (optional)
   * @return mixed Result of the API request
   */
  public static function debug($message, array $data = []) {
    return self::logMessage('debug', $message, $data);
  }

  /**
   * Log warnings
   * 
   * @param string $message Warning message
   * @param array $data Additional data (optional)
   * @return mixed Result of the API request
   */
  public static function warn($message, array $data = []) {
    return self::logMessage('warn', $message, $data);
  }

  /**
   * Log critical errors
   * 
   * @param string $message Critical error message
   * @param array $data Additional data (optional)
   * @return mixed Result of the API request
   */
  public static function fatal($message, array $data = []) {
    return self::logMessage('fatal', $message, $data);
  }

  /**
   * Helper method for sending logs through the API
   * 
   * @param string $level Logging level
   * @param string $message Message
   * @param array $data Additional data
   * @return mixed Result of the API request
   */
  private static function logMessage($level, $message, array $data = []) {
    $params = [
      'level' => $level,
      'message' => $message,
      'data' => $data
    ];

    return self::getApi()->log($params);
  }
}
