<?php

/**
 *
 * This file is licensed under the MIT License. See the LICENSE file.
 *
 * @author Dmitry Volynkin <thesaturn@thesaturn.me>
 */

namespace thesaturn\C14BackupTool;

/**
 * Copy paste from psr
 * Class LogLevel
 * @package thesaturn\C14BackupTool
 */
class LogLevel
{
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';
}

/**
 * Logs using echo
 */
class EchoLogger
{
    /**
     * @var callable
     * @see EchoLogger::defaultMessageFormatter()
     */
    private $messageFormatter;

    /**
     * @var string
     */
    private $level;

    /**
     * @var int[]
     */
    protected static $rankings = [
        LogLevel::DEBUG     => 7,
        LogLevel::INFO      => 6,
        LogLevel::NOTICE    => 5,
        LogLevel::WARNING   => 4,
        LogLevel::ERROR     => 3,
        LogLevel::CRITICAL  => 2,
        LogLevel::ALERT     => 1,
        LogLevel::EMERGENCY => 0,
    ];

    /**
     * @param string $level
     */
    public function __construct($level = LogLevel::DEBUG)
    {
        $this->level = $level;
        $this->messageFormatter = array($this, 'defaultMessageFormatter');
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function emergency($message, array $context = array())
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function alert($message, array $context = array())
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function critical($message, array $context = array())
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function error($message, array $context = array())
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function warning($message, array $context = array())
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function notice($message, array $context = array())
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function info($message, array $context = array())
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function debug($message, array $context = array())
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param  string $level
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        $hasLevel = isset(self::$rankings[$level]);

        if (!$hasLevel || ($hasLevel && self::$rankings[$level] <= self::$rankings[$this->level])) {
            $formatter = $this->messageFormatter;
            echo $formatter($level, $message, $context);
            flush();
        }
    }

    /**
     * @param  string $level
     * @param  string $message
     * @param  array  $context
     * @return string
     */
    protected function defaultMessageFormatter($level, $message, array $context = array())
    {
        $message = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), strtoupper($level), $message);
        if ($context) {
            $message .= ' ' . json_encode($context);
        }
        return $message . PHP_EOL;
    }

    /**
     * @param  callable $messageFormatter
     * @return void
     */
    public function setMessageFormatter(callable $messageFormatter)
    {
        $this->messageFormatter = $messageFormatter;
    }

    /**
     * @param  string $level
     * @return void
     */
    public function setLevel($level)
    {
        $this->level = $level;
    }
}
