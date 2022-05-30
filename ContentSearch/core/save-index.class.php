<?php
class dsIndex {
    public static function saveAll($ds_recent_update=null, $options=[]) {
        ini_set('max_execution_time', 600);
        ini_set('default_socket_timeout',600);
        $where = [
            'published=1 and deleted=0 and searchable=1 and privateweb=0'
        ];

        if(!$ds_recent_update) {
            static::createTable();
        } else {
            $where[] = 'AND editedon > ' . db()->escape($ds_recent_update);
        }

        if(evo()->documentIdentifier) {
            $where[] = 'AND id != ' . db()->escape(evo()->documentIdentifier);
        }
        $rs = db()->select(
            'id,pagetitle,content,publishedon,editedon,searchable',
            db()->config['table_prefix'] . 'site_content',
            implode(' ', $where)
        );
        while($row = db()->getRow($rs)) {
            if(!$row['content']) {
                continue;
            }
            static::save(
                $row,
                static::get_contents(
                    evo()->makeUrl($row['id'],'','','full') . '?template=blank',
                    $options
                )
            );
        }

    }

    public static function hasPlugin() {
        return db()->getRecordCount(
            db()->select(
                'id',
                db()->config['table_prefix'].'site_plugins',
                "name='DocSearch' AND disabled=0"
            )
        );
    }

    public static function createPlugin() {
        $id = db()->insert(
            [
                'name' => 'DocSearch',
                'description' => '@version 1.0.0',
                'plugincode' => db()->escape(
                    preg_replace(
                        '@^<?php\n*@',
                        '',
                        file_get_contents(__DIR__ . '/config/template/plugin.php')
                    )
                )
            ],
            db()->config['table_prefix'].'site_plugins'
        );
        db()->insert(
            [
                'pluginid' => $id,
                'evtid' => 91
            ],
            db()->config['table_prefix'].'site_plugin_events'
        );
    }

    private static function get_contents($url, $options=[])
    {
        $ch = curl_init();
        curl_setopt_array(
            $ch, [
                CURLOPT_URL => $url,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_MAXREDIRS => 3
            ]);
            if (!empty($options['basicAuth'])) {
                curl_setopt($ch, CURLOPT_USERPWD, $options['basicAuth']);
            }
            if (defined('CURLOPT_AUTOREFERER')) {
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        }
        if (serverv('HTTP_USER_AGENT')) {
            curl_setopt($ch, CURLOPT_USERAGENT, serverv('HTTP_USER_AGENT'));
        }
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private static function getTvs($tv_names) {
        $allfields = static::dbFieldNames();
        $tvs = [];
        foreach($tv_names as $tv_name) {
            $tv_id = array_get($allfields, sprintf('tv.%s.id', $tv_name));
            if(!$tv_id) {
                continue;
            }
            $rs = db()->select(
                '*',
                db()->config['table_prefix'] . 'site_tmplvar_contentvalues',
                'tmplvarid='. db()->escape($tv_id)
            );
            while($row = db()->getRow($rs)) {
                pr($row);exit;
                $tvs[$tv_name][$row['contentid']] = $row['value'];
            }
        }
    }
    private static function fields($fields) {
        $existsFields = static::dbFieldNames();
        $rs = [
            'doc' => [],
            'tv' => []
        ];
        foreach($fields as $k) {
            if(isset($existsFields['doc'][$k])) {
                $rs['doc'][] = $k;
            }
            if(isset($existsFields['tv'][$k])) {
                $rs['tv'][] = $k;
            }
        }
        return $rs;
    }

    private static function dbFieldNames() {
        static $fields = null;
        if($fields) {
            return $fields;
        }
        $rs = db()->query(
            evo()->parseText(
                'show columns from [+prefix+]site_content', [
                    'prefix' => db()->config['table_prefix']
                ]
            )
        );
        $fields = [];
        while($row = db()->getRow($rs)) {
            $fields['doc'][$row['Field']] = $row;
        }
        $rs = db()->select('*', db()->config['table_prefix'] . 'site_tmplvars');
        while($row = db()->getRow($rs)) {
            $fields['tv'][$row['name']] = $row;
        }
        return $fields;
    }

    private static function pathList() {
        'published=1 and deleted=0 and searchable=1 and privateweb=0';
        $rs = db()->select(
            'id,parent',
            db()->config['table_prefix'] . 'site_content',
            'published=1 and deleted=0 and searchable=1 and privateweb=0'
        );
        $doc = [];
        while($row = db()->getRow($rs)) {
            $doc[$row['id']] = $row['parent'];
        }
        $path = [];
        foreach($doc as $id=>$parent) {
            $path[$id] = static::path(0, $parent, $doc);
        }
    }

    private static function path($path=0, $parent, $doc) {
        if($parent!=0) {
            static::path($path, $doc[$parent], $doc);
        }
        return 0;
    }

    private static function save($doc, $text) {
        $plainText = $doc['pagetitle'] . ' ' . static::trimText($text);
        db()->save(
            [
                'doc_id' => $doc['id'],
                'pagetitle' => db()->escape($doc['pagetitle']),
                'plain_text' => db()->escape($plainText),
                'publishedon' => array_get($doc, 'publishedon') ?: array_get($doc, 'editedon', time()),
                'editedon' => array_get($doc, 'editedon', time()),
                'tokens' => db()->escape(
                    static::split_token($plainText)
                )
            ],
            db()->config['table_prefix'] . 'search_content',
            'doc_id=' . $doc['id']
        );
    }

    private static function split_token($str) {

        preg_match_all(
            '/[一-龠]+|[ぁ-ん]+|[ァ-ヴー]+|[a-zA-Z0-9]+|[ａ-ｚＡ-Ｚ０-９]+/u',
            $str,
            $matches
        );
        $match_result = $matches[0];
        foreach($match_result as $i=>$v) {
            if(preg_match('/ます$/u', $v)) {
                unset($match_result[$i]);
            }
            if(preg_match('/です$/u', $v)) {
                unset($match_result[$i]);
            }
        }

        //2つの配列の結合
        $tmp_res1 = array();
        if(count($match_result) > 1){
            for ($i = 0 ; $i < (count($match_result) - 1); $i++){
                $tmp_res1[] = $match_result[$i] . $match_result[$i + 1];
            }
        }

        //3つの配列の結合
        $tmp_res2 = array();
        if(count($match_result) > 2){
            for ($i = 0 ; $i < (count($match_result) - 2); $i++){
                $tmp_res2[] = $match_result[$i] . $match_result[$i + 1] . $match_result[$i + 2];
            }
        }

        $match_result = array_merge($match_result, $tmp_res1, $tmp_res2);
        $tokenMinSize = static::tokenMinSize();
        foreach($match_result as $i=>$v) {
            if(mb_strlen($v) < $tokenMinSize) {
                unset($match_result[$i]);
            }
        }
        return implode(' ', $match_result);

        $tokenMinSize = static::tokenMinSize();
        $pattern = '/[一-龠]+[ぁ-ん]*|[一-龠]+|[ぁ-ん]+|[ァ-ヴー]+|[a-zA-Z0-9]+|[ａ-ｚＡ-Ｚ０-９]+/u';
        preg_match_all($pattern, $str, $matches);
        $token = [];
        foreach($matches[0] as $v) {
            if(mb_strlen($v) < $tokenMinSize) {
                continue;
            }
            if(!preg_match('@です$@u', $v) && !preg_match('@ます$@u', $v)) {
                $token[] = $v;
            }

            if(!preg_match('/^([一-龠]+)([ぁ-ん]+)/u', $v, $row)) {
                continue;
            }

            if($tokenMinSize <= mb_strlen($row[1])) {
                $token[] = $row[1];
            }
            if(mb_strlen($row[2])==1) {
                continue;
            }
            if(mb_strpos($row[2], 'っ')===0) {
                continue;
            }
            if($tokenMinSize <= mb_strlen($row[2])) {
                $token[] = $row[2];
            }
        }
        return implode(' ', $token);
    }
    public static function tokenMinSize() {
        $rs = db()->query("SHOW VARIABLES like 'innodb_ft_min_token_size';");
        $row = db()->getRow($rs);
        if(empty($row['Value'])) {
            return false;
        }
        return $row['Value'];
    }

    private static function trimText($text) {
        return preg_replace(
            '@\s\s+@u',
            ' ',
            trim(
                static::remove_tags(
                    preg_replace(
                        '@<h[1-6].*?>(.+?)</h[1-6]>@is',
                        '[$0]',
                        static::zenhan($text)
                    )
                )
            )
        );
    }
    private static function remove_tags($value, $params = '')
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
        if (strpos($params, '[!') === false && strpos($value, '!]') !== false) {
            $value = preg_replace('@\[!.+?!\]@s', '', $value);
        }
        if (strpos($params, '[+') === false && strpos($value, '+]') !== false) {
            $value = preg_replace('@\[\+.+?\+\]@s', '', $value);
        }
        return hsc(
            strip_tags(
                preg_replace('/&[a-z]+;/s', '', $value),
                $params
            )
        );
    }
    private static function zenhan($string) {
        return str_replace(
            '&amp;',
            '&',
            mb_convert_kana(
                htmlspecialchars_decode($string),
                'Kas'
            )
        );
    }

    public static function saveSystemSetting($key, $value) {
        db()->save(
            [
                'setting_name' => db()->escape($key),
                'setting_value' => db()->escape($value)
            ],
            db()->config['table_prefix'] . 'system_settings',
            sprintf(
                "setting_name='%s'",
                db()->escape($key)
            )
        );
    }
    public static function createTable() {
        db()->query('DROP TABLE IF EXISTS '. db()->config['table_prefix']. 'search_content;');
        return db()->query(
            str_replace(
                '[+prefix+]',
                db()->config['table_prefix'],
                file_get_contents(__DIR__ . '/config/template/create-table.sql')
            )
        );
    }
}
