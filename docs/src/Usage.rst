Usage
=====

This section assumes default values are used for all triggers.
Please refer to :ref:`configuration options <configuration options>`
for more information on how to customize triggers.


Provided commands
-----------------

This module provides the following commands:

..  table:: Commands provided by |project|

    +---------------------------+-------------------------------------------+
    | Command                   | Description                               |
    +===========================+===========================================+
    | ``!filter``               | Displays available filters and a quick    |
    |                           | usage note.                               |
    +---------------------------+-------------------------------------------+
    | |filter|                  | Displays the result of using the given    |
    |                           | *filter* on the given *input*.            |
    +---------------------------+-------------------------------------------+

Example
-------

..  sourcecode:: irc

    20:37:25 <+Foobar> !filter string.rot13 V ybir CUC!
    20:37:27 < Erebot> string.rot13: I love PHP!

..  |filter| replace:: :samp:`!filter {filter} {input}`

..  vim: ts=4 et
