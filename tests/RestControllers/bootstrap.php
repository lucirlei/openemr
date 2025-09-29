<?php

declare(strict_types=1);

use Symfony\Component\EventDispatcher\EventDispatcher;

if (!function_exists('sqlStatement')) {
    function sqlStatement($sql, $bind = [])
    {
        return null;
    }
}

if (!function_exists('sqlFetchArray')) {
    function sqlFetchArray($resource)
    {
        return false;
    }
}

if (!function_exists('sqlListFields')) {
    function sqlListFields($table)
    {
        return [];
    }
}

if (!function_exists('sqlQuery')) {
    function sqlQuery($sql, $bind = [])
    {
        return null;
    }
}

if (!function_exists('sqlStatementNoLog')) {
    function sqlStatementNoLog($sql, $bind = [])
    {
        return null;
    }
}

if (!function_exists('sqlFetchRow')) {
    function sqlFetchRow($resource)
    {
        return false;
    }
}

if (!function_exists('xlt')) {
    function xlt($str)
    {
        return $str;
    }
}

if (!isset($GLOBALS['kernel'])) {
    $GLOBALS['kernel'] = new class {
        public function getEventDispatcher(): EventDispatcher
        {
            return new EventDispatcher();
        }
    };
}

require __DIR__ . '/../../vendor/autoload.php';
