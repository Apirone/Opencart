<?php

namespace ApironeApi;

class LoggerWrapper 
{
    static $loggerInstance;

    static $debugMode;

    public static function setLogger($logger, $debug = false)
    {
        if (is_object($logger) && method_exists($logger, 'write')) {
            self::$loggerInstance = $logger;
            self::$debugMode = $debug;
        } 
        else {
            throw new \InvalidArgumentException('Invalid logger');
        }
    }

    public static function debug($message, $context = [])
    {
        self::log('debug', $message, $context);
    }
    public static function error($message, $context = [])
    {
        self::log('error', $message, $context);
    }

    protected static function log($level, $message, $context = array())
    {
        if ($level == 'debug' && !self::$debugMode) {
            return;
        }

        $replace = self::prepareContext($context);
        $message = strip_tags($message);

        if (!empty($replace)) {
            $message = self::prepareMessage($replace, $message);
        }

        self::$loggerInstance->write(strtoupper($level) . ': ' . $message);
    }

    protected static function prepareMessage($replace, $message)
    {
        foreach ($replace as $key => $object) {
            $label = trim($key, "{}");
            $message .= " \n{$label}: {$object}";
        }

        return $message;
    }

    protected static function prepareContext($context)
    {
        if (!$context) {
            return;
        }

        $replace = array();
        foreach ($context as $key => $value) {
            $replace['{'.$key.'}'] = (is_array($value) || is_object($value)) ? json_encode($value, JSON_PRETTY_PRINT) : $value;
        }

        return $replace;
    }
}
