<?php
include_once __DIR__ . '/core/functions.php';
include_once __DIR__ . '/core/EvoContentSearch.php';

$ds = new EvoContentSearch();
$ds->run();
$ds->setProps();

if(!$ds->get('keyword')) {
    return $ds->get('form');
}

if($ds->config('minChars') && mb_strlen($ds->get('keyword')) < $ds->config('minChars')) {
    return $ds->get('form') . $ds->config('tplResults.enoughCharResult');
}
if(!$ds->get('total')) {
    return $ds->get('form') . $ds->config('tplResults.noResult');
}

return $ds->get('form') . evo()->parseText(
    $ds->config('tplResults.wrap'), [
        'info' => evo()->parseText(
            $ds->config('tplResults.info'), [
                'total'   => $ds->get('total'),
                'time'    => $ds->get('time'),
                'keyword' => $ds->get('keyword')
            ]
        ),
        'results' => $ds->get('results'),
        'paginate' => $ds->get('paginate')
    ]
);
