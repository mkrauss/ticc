<?php

namespace functions;

use Functional as F;

function topological_sort($array, $key_fn, $vertices_fn)
{
    /*
     * Sort an $array topologically based on the arrays of key
     * vertices returned by calling $vertices_fn against each
     * element
     */
    $graph = array_map(
        function ($element) use ($key_fn, $vertices_fn) {
            return ['key' => $key_fn($element),
                    'vertices' => $vertices_fn($element),
                    'element' => $element]; },
        $array);

    $sorted = [];

    while (!empty($graph)) {
        list($free_nodes, $graph) = F\partition(
            $graph,
            function($node) { return empty($node['vertices']); });

        if (empty($free_nodes))
            throw new \Exception('Graph is broken');

        $free_keys = array_column($free_nodes, 'key');

        $graph = array_map(
            function($node) use ($free_keys) {
                $node['vertices'] = F\reject(
                    $node['vertices'],
                    function($key) use ($free_keys) {
                        return in_array($key, $free_keys); });
                return $node;
            }, $graph);

        array_splice($sorted, count($sorted), 0,
                     array_map(function ($node) { return $node['element']; },
                               $free_nodes));}

    return $sorted;
}
