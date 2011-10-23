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

class   Erebot_Module_PhpFilter
extends Erebot_Module_Base
{
    protected $_trigger;
    protected $_cmdHandler;
    protected $_usageHandler;
    protected $_allowedFilters;

    const DEFAULT_ALLOWED_FILTERS = 'string.*,convert.*';

    public function _reload($flags)
    {
        if ($flags & self::RELOAD_MEMBERS) {
            // By default, allow only filters from the
            // "string." & "convert." families of filters.
            $whitelist      =
                explode(
                    ',',
                    $this->parseString(
                        'whitelist',
                        self::DEFAULT_ALLOWED_FILTERS
                    )
                );
            $whitelist      = array_map(array('self', '_normalize'), $whitelist);
            $filters        = stream_get_filters();
            $allowed        =
            $allowedFilters = array();

            foreach ($whitelist as $filter) {
                $allowedFilters[$filter] = substr_count($filter, '.');
            }

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
                $translator = $this->getTranslator(FALSE);
                throw new Exception(
                    $translator->gettext('Could not register Filter trigger')
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
            $this->registerHelpMethod(array($this, 'getHelp'));
        }
    }

    protected function _unload()
    {
    }

    public function getHelp(
        Erebot_Interface_Event_Base_TextMessage $event,
                                                $words
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $translator = $this->getTranslator($chan);
        $trigger    = $this->parseString('trigger', 'filter');

        $bot        = $this->_connection->getBot();
        $moduleName = strtolower(get_class());
        $nbArgs     = count($words);

        if ($nbArgs == 1 && $words[0] == $moduleName) {
            $msg = $translator->gettext(
                'Provides the <b><var name="trigger"/></b> command which '.
                'transforms the given input using some PHP filter.'
            );
            $formatter = new Erebot_Styling($msg, $translator);
            $formatter->assign('trigger', $trigger);
            $this->sendMessage($target, $formatter->render());
            return TRUE;
        }

        if ($nbArgs < 2)
            return FALSE;

        if ($words[1] == $trigger) {
            $msg = $translator->gettext(
                '<b>Usage:</b> !<var name="trigger"/> &lt;<u>filter</u>&gt; '.
                '&lt;<u>input</u>&gt;. Transforms the given '.
                '&lt;<u>input</u>&gt; using the given &lt;<u>filter</u>&gt;. '.
                'The following filters are available: <for from="filters" '.
                'item="filter"><b><var name="filter"/></b></for>.'
            );
            $formatter = new Erebot_Styling($msg, $translator);
            $formatter->assign('trigger', $trigger);
            $formatter->assign('filters', array_keys($this->_allowedFilters));
            $this->sendMessage($target, $formatter->render());
            return TRUE;
        }
    }

    static protected _normalize($a)
    {
        return trim($a);
    }

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
        $translator = $this->getTranslator($chan);
        $trigger    = $this->parseString('trigger', 'filter');
        $message    = $translator->gettext(
            'Usage: <b><var name="cmd"/> &lt;filter&gt; &lt;text&gt;</b>. '.
            'Available filters: <for from="filters" item="filter"><var '.
            'name="filter"/></for>.'
        );

        $tpl = new Erebot_Styling($message, $translator);
        $tpl->assign('cmd', $this->_mainConfig->getCommandsPrefix().$trigger);
        $tpl->assign('filters', array_keys($this->_allowedFilters));
        $this->sendMessage($target, $tpl->render());
        return $event->preventDefault(TRUE);
    }

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
        $translator = $this->getTranslator($chan);

        $allowed    = FALSE;
        $nbDots     = substr_count($filter, '.');
        foreach ($this->_allowedFilters as $allowedFilter => $allowedDots) {
            if (fnmatch($allowedFilter, $filter) && $allowedDots == $nbDots) {
                $allowed = TRUE;
                break;
            }
        }

        $stylingCls = $this->getFactory('!Styling');
        if (!$allowed) {
            $message = $translator->gettext(
                'No such filter "<var name="filter"/>" or filter blocked.'
            );

            $tpl = new $stylingCls($message, $translator);
            $tpl->assign('filter', $filter);
            $this->sendMessage($target, $tpl->render());
            return $event->preventDefault(TRUE);
        }

        $fp = fopen('php://memory', 'w+');
        stream_filter_append($fp, $filter, STREAM_FILTER_WRITE);
        fwrite($fp, $text);
        rewind($fp);
        $text = stream_get_contents($fp);

        $message = '<b><var name="filter"/></b>: <var name="result"/>';
        $tpl = new $stylingCls($message, $translator);
        $tpl->assign('filter', $filter);
        $tpl->assign('result', $text);
        $this->sendMessage($target, $tpl->render());
        return $event->preventDefault(TRUE);
    }

    public function getAvailableFilters()
    {
        return $this->_allowedFilters;
    }
}

