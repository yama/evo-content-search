<?php
class csIndex {
    private $pluginName = 'ContentSearch';
    private $version = '1.0.0';
    private $config;
    private $bearer_token;

    public function __construct($config) {
        $this->config = $config;
        if (!evo()->getConfig('site_status')) {
            $this->bearer_token = bin2hex(random_bytes(64));
        }
    }

    private function config($key, $default=null) {
        return array_get($this->config, $key, $default);
    }

    public function saveAll($cs_recent_update=null, $options=[]) {
        ini_set('max_execution_time', 600);
        ini_set('default_socket_timeout',600);

        $where = [
            'published=1 and deleted=0 and searchable=1 and privateweb=0'
        ];

        if(!$cs_recent_update) {
            $this->createTable();
        } else {
            $where[] = 'AND editedon > ' . db()->escape($cs_recent_update);
        }

        if(evo()->documentIdentifier) {
            $where[] = 'AND id != ' . db()->escape(evo()->documentIdentifier);
        }
        $rs = db()->select(
            'id,pagetitle,description,content,publishedon,editedon,searchable',
            db()->getFullTableName('site_content'),
            implode(' ', $where)
        );

        if ($this->bearer_token) {
            evo()->saveBearerToken($this->bearer_token, time() + 60*60*3);
        }

        while($row = db()->getRow($rs)) {
            if(!$row['content']) {
                continue;
            }
            $this->save(
                $row,
                $this->get_contents(
                    evo()->makeUrl($row['id'],'','','full') . '?template=blank',
                    $options
                )
            );
        }

        evo()->clearCache('full');
    }

    private function treatText($text) {
        $text = preg_replace('@・・+@u', '', $text);
        $text = str_replace("\n", ' ', $text);
        return $text;
    }

    public function hasPlugin() {
        return db()->getRecordCount(
            db()->select(
                'id',
                db()->getFullTableName('site_plugins'),
                sprintf(
                    "name='%s' AND disabled=0",
                    db()->escape($this->pluginName)
                )
            )
        );
    }

    public function createPlugin() {
        $id = db()->insert(
            [
                'name' => db()->escape($this->pluginName),
                'description' => '@version ' . $this->version . "\n" . 'Search plugin for site_content',
                'plugincode' => db()->escape(
                    preg_replace(
                        '@^<?php\n*@',
                        '',
                        file_get_contents(__DIR__ . '/plugin-code.php')
                    )
                )
            ],
            db()->getFullTableName('site_plugins')
        );
        db()->insert(
            [
                'pluginid' => $id,
                'evtid' => 91
            ],
            db()->getFullTableName('site_plugin_events')
        );
    }

    private function get_contents($url, $options=[])
    {
        $ch = curl_init();
        curl_setopt_array(
            $ch, [
                CURLOPT_URL => $url,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_MAXREDIRS => 3,
            ]
        );

        if (!empty($options['basicAuth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $options['basicAuth']);
        }

        if ($this->bearer_token) {
            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer '. $this->bearer_token
                ]
            );
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

    private function save($doc, $text) {
        $content = [];
        if ($this->config('additionalKeywordField')) {
            $content[] = $this->addKeywords($this->config('additionalKeywordField'), $doc['id']);
        }
        $content[] = $doc['pagetitle'];
        $content[] = $doc['description'];
        $content[] = $this->trimText($text);
        $plainText = $this->treatText(implode(' ', $content));
        db()->save(
            [
                'doc_id' => $doc['id'],
                'pagetitle' => db()->escape($doc['pagetitle']),
                'plain_text' => db()->escape($plainText),
                'publishedon' => array_get($doc, 'publishedon') ?: array_get($doc, 'editedon', time()),
                'editedon' => array_get($doc, 'editedon', time()),
                'tokens' => db()->escape(
                    $this->split_token($plainText)
                ),
            ],
            db()->getFullTableName('search_content'),
            'doc_id=' . $doc['id']
        );
    }

    private function addKeywords($fieldName, $docid) {
        $keywords = $this->getField($fieldName, $docid);
        if (!$keywords) {
            return '';
        }
        $keywords = preg_split('/[\n|\s|,]+/', $keywords);
        foreach ($keywords as &$keyword) {
            $keyword = sprintf('[%s]', $keyword);
        }
        return implode(' ', $keywords);
    }

    private function getField($key, $docid) {
        $doc = evo()->getDocumentObject('id', $docid);
        if (!isset($doc[$key])) {
            return '';
        }
        if (!is_array($doc[$key])) {
            return $doc[$key];
        }
        if (isset($doc[$key]['value'])) {
            return $doc[$key]['value'];
        }
        if (isset($doc[$key][2])) {
            return $doc[$key][2];
        }
        return '';
    }

    private function split_token($str) {

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
        $tokenMinSize = $this->tokenMinSize();
        foreach($match_result as $i=>$v) {
            if(mb_strlen($v) < $tokenMinSize) {
                unset($match_result[$i]);
            }
        }
        return implode(' ', $match_result);

        $tokenMinSize = $this->tokenMinSize();
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
    public function tokenMinSize() {
        $rs = db()->query("SHOW VARIABLES like 'innodb_ft_min_token_size';");
        $row = db()->getRow($rs);
        if(empty($row['Value'])) {
            return false;
        }
        return $row['Value'];
    }

    private function trimText($text) {
        return preg_replace(
            '@\s\s+@u',
            ' ',
            trim(
                $this->remove_tags(
                    preg_replace(
                        '@<h[1-6].*?>(.+?)</h[1-6]>@is',
                        '[$0]',
                        $this->zenhan($text)
                    )
                )
            )
        );
    }
    private function remove_tags($value, $params = '')
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
    private function zenhan($string) {
        return str_replace(
            '&amp;',
            '&',
            mb_convert_kana(
                htmlspecialchars_decode($string),
                'Kas'
            )
        );
    }

    public function saveSystemSetting($key, $value) {
        db()->save(
            [
                'setting_name' => db()->escape($key),
                'setting_value' => db()->escape($value)
            ],
            db()->getFullTableName('system_settings'),
            sprintf("setting_name='%s'", db()->escape($key))
        );
    }
    public function createTable() {
        db()->query('DROP TABLE IF EXISTS '. db()->config['table_prefix']. 'search_content;');
        return db()->query(
            str_replace(
                '[+prefix+]',
                db()->config['table_prefix'],
                file_get_contents(__DIR__ . '/create-table.sql')
            )
        );
    }
}
