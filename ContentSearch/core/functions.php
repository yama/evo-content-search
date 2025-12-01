<?php
if (!function_exists('evo')) {
    function evo()
    {
        global $modx;
        if (!$modx) {
            return false;
        }
        return $modx;
    }
}

if (!function_exists('db')) {
    function db()
    {
        return evo()->db;
    }
}

if (!function_exists('manager')) {
    function manager()
    {
        global $modx;
        return $modx->manager;
    }
}

if (!function_exists('docid')) {
    function docid()
    {
        if (event()->name === 'OnDocFormSave') {
            return globalv('id');
        }
        return evo()->documentIdentifier;
    }
}

if (!function_exists('base_path')) {
    function base_path()
    {
        if (defined('MODX_BASE_PATH')) {
            return constant('MODX_BASE_PATH');
        }
        exit('base_path not defined.');
    }
}



if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        return substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }
}


if (!function_exists('hsc')) {
    function hsc($string = '', $flags = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, $encode = null, $double_encode = true)
    {
        if(!$string) {
            return $string;
        }
        if(is_array($string)) {
            foreach($string as $i=>$v) {
                $string[$i] = hsc($v, $flags, $encode, $double_encode);
            }
            return $string;
        }
        if($encode===null) {
            $encode = 'utf-8';
        }
        return htmlspecialchars($string, $flags, $encode, $double_encode);
    }
}

if (!function_exists('parseText')) {
    function parseText($tpl, $ph, $left = '[+', $right = '+]', $execModifier = false)
    {
        if (evo()) {
            return evo()->parseText($tpl, $ph, $left, $right, $execModifier);
        }
        foreach ($ph as $k => $v) {
            $k = sprintf('[+%s+]', $k);
            $tpl = str_replace($k, $v, $tpl);
        }
        return $tpl;
    }
}

if (!function_exists('array_get')) {
    function array_get($array, $key = null, $default = null, $validate = null)
    {
        if ($key === null || trim($key) == '') {
            return $array;
        }

        if (isset($array[$key])) {
            if ($validate && is_callable($validate) && !$validate($array[$key])) {
                return $default;
            }
            return $array[$key];
        }
        $segments = explode('.', $key);
        foreach ($segments as $segment) {
            if (!is_array($array) || !isset($array[$segment])) {
                return $default;
            }
            $array = $array[$segment];
        }
        if ($validate && is_callable($validate) && !$validate($array)) {
            return $default;
        }
        return $array;
    }
}

if (!function_exists('array_set')) {
    function array_set(&$array, $key, $value)
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', $key);
        foreach ($keys as $i => $key) {
            if (count($keys) === 1) {
                break;
            }
            unset($keys[$i]);
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }
}

if (!function_exists('event')) {
    function event()
    {
        return evo()->event;
    }
}

if (!function_exists('parent')) {
    function parent($docid)
    {
        if (evo()) {
            return evo()->getParentID($docid ?: docid());
        }
    }
}

if (!function_exists('uparent')) {
    function uparent($docid = null, $top = 0)
    {
        if (evo()) {
            return evo()->getUltimateParentId($docid ?: docid(), $top);
        }
    }
}

if (!function_exists('getv')) {
    function getv($key = null, $default = null)
    {
        $request = $_GET;
        if(isset($request[$key]) && $request[$key]==='') {
            unset($request[$key]);
        }
        return array_get($request, $key, $default);
    }
}

if (!function_exists('postv')) {
    function postv($key = null, $default = null)
    {
        return array_get($_POST, $key, $default);
    }
}

if (!function_exists('cookiev')) {
    function cookiev($key = null, $default = null)
    {
        return array_get($_COOKIE, $key, $default);
    }
}

if (!function_exists('anyv')) {
    function anyv($key = null, $default = null)
    {
        return array_get($_REQUEST, $key, $default);
    }
}

if (!function_exists('serverv')) {
    function serverv($key = null, $default = null)
    {
        if ($key === null) {
            return $_SERVER;
        }
        if (!is_string($key)) {
            return $default;
        }
        return array_get($_SERVER, strtoupper($key), $default);
    }
}

if (!function_exists('sessionv')) {
    function sessionv($key = null, $default = null)
    {
        if (!is_string($key)) {
            return $default;
        }
        if (str_starts_with($key, '*')) {
            return array_set($_SESSION, ltrim($key, '*'), $default);
        }
        return array_get($_SESSION, $key, $default);
    }
}

if (!function_exists('filev')) {
    function filev($key = null, $default = null)
    {
        return array_get($_FILES, $key, $default);
    }
}

if (!function_exists('globalv')) {
    function globalv($key = null, $default = null)
    {
        if ($key === null) {
            return $GLOBALS;
        }
        if (!is_string($key)) {
            return $default;
        }
        if (str_starts_with($key, '*')) {
            return array_set($GLOBALS, ltrim($key, '*'), $default);
        }
        return array_get($GLOBALS, $key, $default);
    }
}

if (!function_exists('pr')) {
    function pr($content)
    {
        if (is_array($content)) {
            echo '<pre>' . print_r(array_map('hsc', $content), true) . '</pre>';
            return;
        }
        echo '<pre>' . hsc($content) . '</pre>';
    }
}

if (!function_exists('real_ip')) {
    function real_ip()
    {
        return serverv(
            'http_client_ip',
            serverv(
                'http_x_forwarded_for',
                serverv(
                    'remote_addr',
                    'UNKNOWN'
                )
            )
        );
    }
}

if (!function_exists('user_agent')) {
    function user_agent()
    {
        return serverv('http_user_agent', '');
    }
}

if (!function_exists('doc')) {
    function doc($key, $default = '')
    {
        global $modx, $docObject;
        if (isset($docObject)) {
            $doc = &$docObject;
        } elseif (!empty($modx->documntObject)) {
            $doc = &$modx->documntObject;
        } else {
            $docObject = evo()->getDocumentObject('id', evo()->documentIdentifier);
            $doc = &$docObject;
        }
        return array_get($doc, $key, $default);
    }
}

if (!function_exists('request_uri')) {
    function request_uri()
    {
        return serverv('request_uri');
    }
}

if (!function_exists('easy_hash')) {
    function easy_hash($seed)
    {
        return strtr(rtrim(base64_encode(pack('H*', hash('adler32', $seed))), '='), '+/', '-_');
    }
}

if (!function_exists('datetime_format')) {
    function datetime_format($format, $timestamp = '', $default = '')
    {
        if (!$timestamp || strpos($timestamp, '0000-00-00') === 0) {
            return $default;
        }
        if (!preg_match('@^[0-9]+$@', $timestamp)) {
            $timestamp = strtotime(
                preg_replace('@^([0-9]+)/([0-9]+)/([0-9]+)@', '$1-$2-$3', $timestamp)
            );
        }
        if (strpos($format, '%') === false) {
            return date($format, $timestamp);
        }
        if (strpos($format, '%曜') === false) {
            return strftime($format, $timestamp);
        }
        $week = ['日', '月', '火', '水', '木', '金', '土'];
        return strftime(
            str_replace('%曜', $week[date('w', $timestamp)], $format),
            $timestamp
        );
    }
}

if (!function_exists('request_time')) {
    function request_time()
    {
        return serverv('request_time', time());
    }
}

if (!function_exists('remove_tags')) {
    function remove_tags($value, $params = '')
    {
        if (stripos($params, 'style') === false && stripos($value, '</style>') !== false) {
            $value = preg_replace('#<style.*?>.*?</style>#is', '', $value);
        }
        if (stripos($params, 'script') === false && stripos($value, '</script>') !== false) {
            $value = preg_replace('@<script.*?>.*?</script>@is', '', $value);
        }
        if (strpos($params, '[[') === false && strpos($value, ']]') !== false) {
            $value = preg_replace('@\[\[.+?\]\]@s', '', $value);
        }
        return strip_tags($value, $params);
    }
}

if (!function_exists('validated')) {
    function validated($key, $default = null)
    {
        if($key==='id') {
            return globalv('id');
        }
        if($key==='editedon') {
            return time();
        }
        return postv($key);
    }
}

if(!function_exists('__')) {
    function __($key) {
        return globalv('_lang.'.$key, $key);
    }
}
