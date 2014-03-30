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

namespace Erebot\Module;

/**
 *  \brief
 *      A module that processes its input using PHP stream filters
 *      (http://php.net/stream.filters.php).
 */
class PhpFilter extends \Erebot\Module\Base implements \Erebot\Interfaces\HelpEnabled
{
    /// Token for the trigger associated with this module.
    protected $trigger;

    /// Handler for filter requests.
    protected $cmdHandler;

    /// Handler for help requests.
    protected $usageHandler;

    /// List of allowed filters, overrides DEFAULT_ALLOWED_FILTERS.
    protected $allowedFilters;

    /// Default list of filters, considered safe.
    const DEFAULT_ALLOWED_FILTERS = 'string.*,convert.*';

    /**
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot::Module::Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function reload($flags)
    {
        if ($flags & self::RELOAD_MEMBERS) {
            return $this->reloadMembers();
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $registry   = $this->connection->getModule(
                '\\Erebot\\Module\\TriggerRegistry'
            );

            if (!($flags & self::RELOAD_INIT)) {
                $this->connection->removeEventHandler($this->cmdHandler);
                $this->connection->removeEventHandler($this->usageHandler);
                $registry->freeTriggers($this->trigger, $registry::MATCH_ANY);
            }

            $trigger        = $this->parseString('trigger', 'filter');
            $this->trigger  = $registry->registerTriggers($trigger, $registry::MATCH_ANY);
            if ($this->trigger === null) {
                $fmt = $this->getFormatter(false);
                throw new \Exception(
                    $fmt->_('Could not register Filter trigger')
                );
            }

            $this->cmdHandler   = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleFilter')),
                new \Erebot\Event\Match\All(
                    new \Erebot\Event\Match\Type(
                        '\\Erebot\\Interfaces\\Event\\Base\\TextMessage'
                    ),
                    new \Erebot\Event\Match\TextWildcard($trigger.' & *', true)
                )
            );
            $this->connection->addEventHandler($this->cmdHandler);

            $this->usageHandler  = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleUsage')),
                new \Erebot\Event\Match\All(
                    new \Erebot\Event\Match\Type(
                        '\\Erebot\\Interfaces\\Event\\Base\\TextMessage'
                    ),
                    new \Erebot\Event\Match\Any(
                        new \Erebot\Event\Match\TextStatic($trigger, true),
                        new \Erebot\Event\Match\TextWildcard($trigger.' &', true)
                    )
                )
            );
            $this->connection->addEventHandler($this->usageHandler);
        }
    }

    /**
     * Reloads instance members.
     * This method is called by _reload().
     * Don't try to call it yourself unless
     * you know what your're doing!
     */
    protected function reloadMembers()
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
        $whitelist  = array_map(
            function ($name) {
                return trim($name);
            },
            $whitelist
        );
        $filters    = stream_get_filters();

        $allowedFilters = array();
        foreach ($whitelist as $filter) {
            $allowedFilters[$filter] = substr_count($filter, '.');
        }

        foreach ($filters as $filter) {
            $nbDots = substr_count($filter, '.');
            foreach ($allowedFilters as $allowedFilter => $allowedDots) {
                if (fnmatch($allowedFilter, $filter) &&
                    $allowedDots == $nbDots) {
                    $this->allowedFilters[$filter] = $nbDots;
                    break;
                }
            }
        }
    }

    /**
     * Provides help about this module.
     *
     * \param Erebot::Interfaces::Event::Base::TextMessage $event
     *      Some help request.
     *
     * \param Erebot::Interfaces::TextWrapper $words
     *      Parameters passed with the request. This is the same
     *      as this module's name when help is requested on the
     *      module itself (in opposition with help on a specific
     *      command provided by the module).
     */
    public function getHelp(
        \Erebot\Interfaces\Event\Base\TextMessage   $event,
        \Erebot\Interfaces\TextWrapper              $words
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Base\PrivateMessage) {
            $target = $event->getSource();
            $chan   = null;
        } else {
            $target = $chan = $event->getChan();
        }

        $fmt        = $this->getFormatter($chan);
        $trigger    = $this->parseString('trigger', 'filter');
        $nbArgs     = count($words);
        if ($nbArgs == 1 && $words[0] === get_called_class()) {
            $msg = $fmt->_(
                'Provides the <b><var name="trigger"/></b> command which '.
                'transforms the given input using some PHP filter.',
                array('trigger' => $trigger)
            );
            $this->sendMessage($target, $msg);
            return true;
        }

        if ($nbArgs < 2) {
            return falsefalse;
        }

        if ($words[1] == $trigger) {
            $msg = $fmt->_(
                '<b>Usage:</b> !<var name="trigger"/> &lt;<u>filter</u>&gt; '.
                '&lt;<u>input</u>&gt;. Transforms the given '.
                '&lt;<u>input</u>&gt; using the given &lt;<u>filter</u>&gt;. '.
                'The following filters are available: <for from="filters" '.
                'item="filter"><b><var name="filter"/></b></for>.',
                array(
                    'trigger' => $trigger,
                    'filters' => array_keys($this->allowedFilters),
                )
            );
            $this->sendMessage($target, $msg);
            return true;
        }
    }

    /**
     * Handles a request for usage help.
     *
     * \param Erebot::Interfaces:EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Base::TextMessage $event
     *      Help request.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleUsage(
        \Erebot\Interfaces\EventHandler           $handler,
        \Erebot\Interfaces\Event\Base\TextMessage $event
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Base\PrivateMessage) {
            $target = $event->getSource();
            $chan   = null;
        } else {
            $target = $chan = $event->getChan();
        }

        $fmt        = $this->getFormatter($chan);
        $trigger    = $this->parseString('trigger', 'filter');

        $serverCfg  = $this->connection->getConfig(null);
        $mainCfg    = $serverCfg->getMainCfg();
        $msg        = $fmt->_(
            'Usage: <b><var name="cmd"/> &lt;filter&gt; &lt;text&gt;</b>. '.
            'Available filters: <for from="filters" item="filter"><var '.
            'name="filter"/></for>.',
            array(
                'cmd' => $mainCfg->getCommandsPrefix().$trigger,
                'filters' => array_keys($this->allowedFilters)
            )
        );
        $this->sendMessage($target, $msg);
        return $event->preventDefault(true);
    }

    /**
     * Handles a request to process some text using
     * a PHP stream filter.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Base::TextMessage $event
     *      Some input to process.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleFilter(
        \Erebot\Interfaces\EventHandler           $handler,
        \Erebot\Interfaces\Event\Base\TextMessage $event
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Base\PrivateMessage) {
            $target = $event->getSource();
            $chan   = null;
        } else {
            $target = $chan = $event->getChan();
        }

        $filter     = $event->getText()->getTokens(1, 1);
        $text       = $event->getText()->getTokens(2);
        $fmt        = $this->getFormatter($chan);

        $allowed    = false;
        $nbDots     = substr_count($filter, '.');
        foreach ($this->allowedFilters as $allowedFilter => $allowedDots) {
            if (fnmatch($allowedFilter, $filter) && $allowedDots == $nbDots) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            $msg = $fmt->_(
                'No such filter "<var name="filter"/>" or filter blocked.',
                array('filter' => $filter)
            );
            $this->sendMessage($target, $msg);
            return $event->preventDefault(true);
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
        return $event->preventDefault(true);
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
        return $this->allowedFilters;
    }
}
