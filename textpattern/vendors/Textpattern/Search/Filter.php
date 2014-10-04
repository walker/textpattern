<?php

/*
 * Textpattern Content Management System
 * http://textpattern.com
 *
 * Copyright (C) 2014 The Textpattern Development Team
 *
 * This file is part of Textpattern.
 *
 * Textpattern is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2.
 *
 * Textpattern is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Textpattern. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Admin-side search.
 *
 * A collection of search-related features that allow search forms to be output
 * and permit the DB to be queried using sets of pre-defined criteria-to-DB-field
 * mappings.
 *
 * @since   4.6.0
 * @package Search
 */

class Textpattern_Search_Filter
{
    /**
     * The filter's event.
     *
     * @var string
     */

    public $event;

    /**
     * The available search methods as an array of Textpattern_Search_Method.
     *
     * @var array
     */

    protected $methods;

    /**
     * The filter's in-use search method(s).
     *
     * @var string|array
     */

    protected $search_method;

    /**
     * The filter's user-supplied search criteria.
     *
     * @var string
     */

    protected $crit;

    /**
     * The SQL-safe (escaped) filter's search criteria.
     *
     * @var string
     */

    protected $crit_escaped;

    /**
     * Whether the user-supplied search criteria is to be considered verbatim (quoted) or not.
     *
     * @var bool
     */

    protected $verbatim;

    /**
     * General constructor for searches.
     *
     * @param string $event   The admin-side event to which this search relates
     * @param string $methods Available search methods
     * @param string $crit    Criteria to be used in filter. If omitted, uses GET/POST value
     * @param string $method  Search method(s) to filter by. If omitted, uses GET/POST value or last-used method
     */

    public function __construct($event, $methods, $crit = null, $method = null)
    {
        $this->event = $event;

        callback_event_ref('search_criteria', $event, 0, $methods);

        $this->setMethods($methods);

        if ($crit === null) {
            $this->crit = gps('crit');
        }

        if ($method === null) {
            $this->search_method = gps('search_method');

            if ($this->search_method === '') {
                $this->search_method = unserialize(get_pref('search_options_'.$event));
            }
        }

        $this->verbatim = (bool) preg_match('/^"(.*)"$/', $this->crit, $m);
        $this->crit_escaped = ($this->verbatim) ? doSlash($m[1]) : doLike($this->crit);
    }

    /**
     * Sets filter's search methods.
     *
     * @param array $methods Array of column indices and their human-readable names/types
     */

    private function setMethods($methods)
    {
        foreach ($methods as $key => $atts) {
            $this->methods[$key] = new Textpattern_Search_Method($key, $atts);
        }
    }

    /**
     * Sets filter's options.
     *
     * @param array $options Array of method indices and their corresponding array of attributes
     */

    private function setOptions($options)
    {
        foreach ($options as $method => $opts) {
            if (isset($this->methods[$method])) {
                $this->methods[$method]->setOptions($opts);
            }
        }
    }

    /**
     * Sets a method's aliases.
     *
     * @param string $method Method index to which the aliases should apply
     * @param array  $tuples DB criteria => comma-separated list of user criteria values that are equivalent to it
     */

    public function setAliases($method, $tuples)
    {
        if (isset($this->methods[$method])) {
            foreach ($tuples as $key => $value) {
                $columns = $this->methods[$method]->getInfo('column');

                if (!$this->verbatim) {
                    $value = strtolower($value);
                }

                foreach ($columns as $column) {
                    $this->methods[$method]->setAlias($column, $key, do_list($value));
                }
            }
        }
    }

    /**
     * Generates SQL statements from the current criteria and search_method.
     *
     * @param  array $options Options
     * @return array The criteria SQL, searched value and the search locations
     */

    public function getFilter($options = array())
    {
        $out = array('criteria' => 1);

        if ($this->search_method && $this->crit !== '') {
            $this->setOptions($options);

            $search_criteria = array();

            if (!is_array($this->search_method)) {
                $this->search_method = do_list($this->search_method);
            }

            foreach ($this->search_method as $selected_method) {
                if (array_key_exists($selected_method, $this->methods)) {
                    $search_criteria[] = join(' or ', $this->methods[$selected_method]->getCriteria($this->crit_escaped, $this->verbatim));
                }
            }

            if ($search_criteria) {
                $out['crit'] = $this->crit;
                $out['criteria'] = join(' or ', $search_criteria);

                if (is_array($this->search_method)) {
                    $out['search_method'] = join(',', $this->search_method);
                    set_pref('search_options_' . $this->event, serialize($this->search_method), $this->event, PREF_HIDDEN, 'text_input', 0, PREF_PRIVATE);
                }
            } else {
                $out['crit'] = '';
                $out['search_method'] = get_pref('search_options_' . $this->event);
            }
        } else {
            $out['crit'] = '';
            $out['search_method'] = get_pref('search_options_' . $this->event);
        }

        $out['criteria'] .= callback_event('admin_criteria', $this->event . '_list', 0, $out['criteria']);

        return array_values($out);
    }

    /**
     * Renders an admin-side search form.
     *
     * @param  string $step    Textpattern Step for the form submission
     * @param  array  $options Options
     * @return string HTML
     */

    public function renderForm($step, $options = array())
    {
        static $id_counter = 0;

        $event = $this->event;
        $methods = $this->getMethods();
        $selected = $this->search_method;

        extract(lAtts(array(
            'default_method' => 'all',
            'submit_as'      => 'get', // or 'post'
            'placeholder'    => '',
            'label_all'      => 'search_all',
            'class'          => '',
        ), (array)$options));

        $selected = ($selected) ? $selected : $default_method;
        $submit_as = (in_array($submit_as, array('get', 'post')) ? $submit_as : 'get');

        if (!is_array($selected)) {
            $selected = do_list($selected);
        }

        $set_all = ( (count($selected) === 1 && $selected[0] === 'all') || (count($selected) === count($methods)) );

        if ($label_all) {
            $methods = array('all' => gTxt($label_all)) + $methods;
        }

        $method_list = array();

        foreach ($methods as $key => $value) {
            $name = ($key === 'all') ? 'select_all' : 'search_method[]';
            $method_list[] = tag(
                checkbox($name, $key, ($set_all || in_array($key, $selected)), 0, 'search-'.$key.$id_counter).
                n.tag($value, 'label', array('for' => 'search-'.$key.$id_counter)),
                'li'
            );
        }

        $button_set = '<button class="txp-search-button">' . gTxt('search') . '</button>';

        if (count($method_list) > 1) {
            $button_set .= n.'<button class="txp-search-options">' . gTxt('search_options') . '</button>';
        }

        $buttons = tag($button_set, 'span', array('class' => 'txp-search-buttons'));

        // So the search can be used multiple times on a page without id clashes.
        $id_counter++;

        // TODO: consider moving Route.add() to textpattern.js, but that involves adding one
        // call per panel that requires search, instead of auto-adding it when invoked here.
        return form(
            graf(
                fInput('search', 'crit', $this->crit, 'input-medium', '', '', INPUT_MEDIUM, 0, '', false, false, gTxt($placeholder)).
                eInput($event).
                sInput($step).
                $buttons
            ).
            tag(join(n, $method_list), 'ul', array('class' => 'txp-dropdown'))
            , '', '', $submit_as, 'txp-search' . ($class ? ' '.$class : ''), '', '', 'search'
            ).
            script_js( <<<EOJS
textpattern.Route.add('{$event}', txp_search);
EOJS
            );
    }

    /**
     * Returns all methods as a simple id->label array.
     *
     * @return array
     */

    public function getMethods()
    {
        $out = array();

        foreach ($this->methods as $key => $method) {
            $out[$key] = $this->methods[$key]->getInfo('label');
        }

        return $out;
    }
}
