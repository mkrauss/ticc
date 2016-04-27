<?php
/**
 * Transactional Iterative Changes - Manage database changes
 *
 * Copyright (C) 2016 Matthew Krauss
 * Copyright (C) 2016 Matthew Carter
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Report issues at: https://github.com/mkrauss/ticc
 */

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
