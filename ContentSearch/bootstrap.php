<?php
include_once 'define-path.php';
include_once 'core/functions.php';
include_once 'core/EvoContentSearch.php';

$search = new EvoContentSearch();
$search->run();
$search->setProps();

if (!$search->config('returnResponse')) {
    return '';
}

if (!$search->get('keyword')) {
    return $search->get('form');
}

if ($search->config('minChars') && mb_strlen($search->get('keyword')) < $search->config('minChars')) {
    return $search->get('form') . $search->config('tplResults.enoughCharResult');
}

if (!$search->get('total')) {
    return $search->get('form') . $search->config('tplResults.noResult');
}

return $search->get('form') . evo()->parseText(
    $search->config('tplResults.wrap'), [
        'info' => evo()->parseText(
            $search->config('tplResults.info'), [
                'total'   => $search->get('total'),
                'time'    => $search->get('time'),
                'keyword' => $search->get('keyword')
            ]
        ),
        'results' => $search->get('results'),
        'paginate' => $search->get('paginate')
    ]
);
