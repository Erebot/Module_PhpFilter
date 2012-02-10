<?php
/*
    This file is part of Erebot.

    Erebot is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Erebot is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Erebot.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 *  \brief
 *      A module that processes its input using PHP stream filters
 *      (http://php.net/stream.filters.php).
 */
class   Erebot_Module_PhpFilter
extends Erebot_Module_Base
{
    /// Token for the trigger associated with this module.
    protected $_trigger;

    /// Handler for filter requests.
    protected $_cmdHandler;

    /// Handler for help requests.
    protected $_usageHandler;

    /// List of allowed filters, overrides DEFAULT_ALLOWED_FILTERS.
    protected $_allowedFilters;

    /// Default list of filters, considered safe.
    const DEFAULT_ALLOWED_FILTERS = 'string.*,convert.*';

    /**
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot_Module_Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function _reload($flags)
    {
        if ($flags & self::RELOAD_MEMBERS)
            return $this->_reloadMembers();

        if ($flags & self::RELOAD_HANDLERS) {
            $registry   = $this->_connection->getModule(
                'Erebot_Module_TriggerRegistry'
            );
            $matchAny  = Erebot_Utils::getVStatic($registry, 'MATCH_ANY');

            if (!($flags & self::RELOAD_INIT)) {
                $this->_connection->removeEventHandler($this->_cmdHandler);
                $this->_connection->removeEventHandler($this->_usageHandler);
                $registry->freeTriggers($this->_trigger, $matchAny);
            }

            $trigger        = $this->parseString('trigger', 'filter');
            $this->_trigger  = $registry->registerTriggers($trigger, $matchAny);
            if ($this->_trigger === NULL) {
                $fmt = $this->getFormatter(FALSE);
                throw new Exception(
                    $fmt->_('Could not register Filter trigger')
                );
            }

            $this->_cmdHandler   = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleFilter')),
                new Erebot_Event_Match_All(
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Base_TextMessage'
                    ),
                    new Erebot_Event_Match_TextWildcard($trigger.' & *', TRUE)
                )
            );
            $this->_connection->addEventHandler($this->_cmdHandler);

            $this->_usageHandler  = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleUsage')),
                new Erebot_Event_Match_All(
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Base_TextMessage'
                    ),
                    new Erebot_Event_Match_Any(
                        new Erebot_Event_Match_TextStatic($trigger, TRUE),
                        new Erebot_Event_Match_TextWildcard($trigger.' &', TRUE)
                    )
                )
            );
            $this->_connection->addEventHandler($this->_usageHandler);

            $cls = $this->getFactory('!Callable');
            $this->registerHelpMethod(new $cls(array($this, 'getHelp')));
        }
    }

    /**
     * Reloads instance members.
     * This method is called by _reload().
     * Don't try to call it yourself unless
     * you know what your're doing!
     */
    protected function _reloadMembers()
    {
        // By default, allow only filters from the
        // "string." & "convert." families of filters.
        $whitelist  =
            explode(
                ',',
                $this->parseString(
                    'whitelist',
                    self::DEFAULT_ALLOWED_FILTERS
                )
            );
        $whitelist  = array_map(array('self', '_normalize'), $whitelist);
        $filters    = stream_get_filters();

        $allowedFilters = array();
        foreach ($whitelist as $filter)
            $allowedFilters[$filter] = substr_count($filter, '.');

        foreach ($filters as $filter) {
            $nbDots = substr_count($filter, '.');
            foreach ($allowedFilters as $allowedFilter => $allowedDots) {
                if (fnmatch($allowedFilter, $filter) &&
                    $allowedDots == $nbDots) {
                    $this->_allowedFilters[$filter] = $nbDots;
                    break;
                }
            }
        }
    }

    /**
     * Provides help about this module.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Some help request.
     *
     * \param Erebot_Interface_TextWrapper $words
     *      Parameters passed with the request. This is the same
     *      as this module's name when help is requested on the
     *      module itself (in opposition with help on a specific
     *      command provided by the module).
     */
    public function getHelp(
        Erebot_Interface_Event_Base_TextMessage $event,
        Erebot_Interface_TextWrapper            $words
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $fmt        = $this->getFormatter($chan);
        $trigger    = $this->parseString('trigger', 'filter');
        $moduleName = strtolower(get_class());
        $nbArgs     = count($words);

        if ($nbArgs == 1 && $words[0] == $moduleName) {
            $msg = $fmt->_(
                'Provides the <b><var name="trigger"/></b> command which '.
                'transforms the given input using some PHP filter.',
                array('trigger' => $trigger)
            );
            $this->sendMessage($target, $msg);
            return TRUE;
        }

        if ($nbArgs < 2)
            return FALSE;

        if ($words[1] == $trigger) {
            $msg = $fmt->_(
                '<b>Usage:</b> !<var name="trigger"/> &lt;<u>filter</u>&gt; '.
                '&lt;<u>input</u>&gt;. Transforms the given '.
                '&lt;<u>input</u>&gt; using the given &lt;<u>filter</u>&gt;. '.
                'The following filters are available: <for from="filters" '.
                'item="filter"><b><var name="filter"/></b></for>.',
                array(
                    'trigger' => $trigger,
                    'filters' => array_keys($this->_allowedFilters),
                )
            );
            $this->sendMessage($target, $msg);
            return TRUE;
        }
    }

    /**
     * Normalizes a filter name.
     *
     * \param string $a
     *      A filter name to normalize.
     *
     * \retval string
     *      Normalized name for the filter.
     */
    static protected function _normalize($a)
    {
        return trim($a);
    }

    /**
     * Handles a request for usage help.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Help request.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleUsage(
        Erebot_Interface_EventHandler           $handler,
        Erebot_Interface_Event_Base_TextMessage $event
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $fmt        = $this->getFormatter($chan);
        $trigger    = $this->parseString('trigger', 'filter');

        $serverCfg  = $this->_connection->getConfig(NULL);
        $mainCfg    = $serverCfg->getMainCfg();
        $msg        = $fmt->_(
            'Usage: <b><var name="cmd"/> &lt;filter&gt; &lt;text&gt;</b>. '.
            'Available filters: <for from="filters" item="filter"><var '.
            'name="filter"/></for>.',
            array(
                'cmd' => $mainCfg->getCommandsPrefix().$trigger,
                'filters' => array_keys($this->_allowedFilters)
            )
        );
        $this->sendMessage($target, $msg);
        return $event->preventDefault(TRUE);
    }

    /**
     * Handles a request to process some text using
     * a PHP stream filter.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Some input to process.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleFilter(
        Erebot_Interface_EventHandler           $handler,
        Erebot_Interface_Event_Base_TextMessage $event
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();
        $filter     = $event->getText()->getTokens(1, 1);
        $text       = $event->getText()->getTokens(2);
        $fmt        = $this->getFormatter($chan);

        $allowed    = FALSE;
        $nbDots     = substr_count($filter, '.');
        foreach ($this->_allowedFilters as $allowedFilter => $allowedDots) {
            if (fnmatch($allowedFilter, $filter) && $allowedDots == $nbDots) {
                $allowed = TRUE;
                break;
            }
        }

        if (!$allowed) {
            $msg = $fmt->_(
                'No such filter "<var name="filter"/>" or filter blocked.',
                array('filter' => $filter)
            );
            $this->sendMessage($target, $msg);
            return $event->preventDefault(TRUE);
        }

        $fp = fopen('php://memory', 'w+');
        stream_filter_append($fp, $filter, STREAM_FILTER_WRITE);
        fwrite($fp, $text);
        rewind($fp);
        $text = stream_get_contents($fp);

        $msg = $fmt->_(
            '<b><var name="filter"/></b>: <var name="result"/>',
            array(
                'filter' => $filter,
                'result' => $text,
            )
        );
        $this->sendMessage($target, $msg);
        return $event->preventDefault(TRUE);
    }

    /**
     * Returns a list of supported filters.
     *
     * \retval array
     *      A list of filter names that can be used
     *      with this module.
     *
     * \note
     *      Not all filters supported by PHP may
     *      necessarily be supported by this module.
     *      Especially, filters that return binary
     *      data are not suitable for this module.
     */
    public function getAvailableFilters()
    {
        return $this->_allowedFilters;
    }
}

