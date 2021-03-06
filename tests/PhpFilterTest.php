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

class   PhpFilterTest
extends Erebot_Testenv_Module_TestCase
{
    public function setUp()
    {
        $this->_module = new \Erebot\Module\PhpFilter('#test');
        parent::setUp();

        $this->_connection
            ->expects($this->any())
            ->method('getModule')
            ->will($this->returnValue($this));

        $this->_serverConfig
            ->expects($this->any())
            ->method('parseString')
            ->will($this->returnValue('string.*,convert.*'));

        $this->_module->reloadModule(
            $this->_connection,
            \Erebot\Module\Base::RELOAD_MEMBERS |
            \Erebot\Module\Base::RELOAD_INIT
        );
    }

    public function tearDown()
    {
        $this->_module->unloadModule();
        parent::tearDown();
    }

    protected function _mockMessage($text)
    {
        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\ChanText')->getMock();
        $wrapper = $this->getMockBuilder('\\Erebot\\Interfaces\\TextWrapper')->getMock();
        $text = explode(" ", $text, 3);
        $wrapper
            ->expects($this->any())
            ->method('getTokens')
            ->will($this->onConsecutiveCalls($text[1], $text[2]));

        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue('Tester'));
        $event
            ->expects($this->any())
            ->method('getChan')
            ->will($this->returnValue('#test'));
        $event
            ->expects($this->any())
            ->method('getText')
            ->will($this->returnValue($wrapper));
        return $event;
    }


    public function testDefaultWhitelist()
    {
        $expectedWhitelist = array(
            'convert.*',
            'string.rot13',
            'string.strip_tags',
            'string.tolower',
            'string.toupper',
        );
        $actualWhitelist = array_keys($this->_module->getAvailableFilters());
        sort($actualWhitelist);
        $this->assertEquals(
            $expectedWhitelist,
            $actualWhitelist
        );
    }

    public function testBase64Filter()
    {
        $event = $this->_mockMessage('!filter convert.base64-encode PHP');
        $this->_module->handleFilter($this->_eventHandler, $event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            "PRIVMSG #test :\002convert.base64-encode\002: UEhQ",
            $this->_outputBuffer[0]
        );

        // Clear the output buffer.
        $this->_outputBuffer = array();
        $event = $this->_mockMessage('!filter convert.base64-decode UEhQ');
        $this->_module->handleFilter($this->_eventHandler, $event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            "PRIVMSG #test :\002convert.base64-decode\002: PHP",
            $this->_outputBuffer[0]
        );
    }

    public function testRot13Filter()
    {
        $event = $this->_mockMessage('!filter string.rot13 PHP');
        $this->_module->handleFilter($this->_eventHandler, $event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            "PRIVMSG #test :\002string.rot13\002: CUC",
            $this->_outputBuffer[0]
        );
    }

    public function testUnknownFilter()
    {
        $event = $this->_mockMessage('!filter surely.this.does.not.exist !!');
        $this->_module->handleFilter($this->_eventHandler, $event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            'PRIVMSG #test :No such filter "surely.this.does.not.exist" or filter blocked.',
            $this->_outputBuffer[0]
        );
    }
}

