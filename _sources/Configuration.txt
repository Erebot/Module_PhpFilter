Configuration
=============

..  _`configuration options`:

Options
-------

This module provides several configuration options.

..  table:: Options for |project|

    +-----------+-----------+-----------+-----------------------------------+
    | Name      | Type      | Default   | Description                       |
    |           |           | value     |                                   |
    +===========+===========+===========+===================================+
    | trigger   | string    | "filter"  | The command to use to ask the bot |
    |           |           |           | to transform a text using a       |
    |           |           |           | filter.                           |
    +-----------+-----------+-----------+-----------------------------------+
    | whitelist | string    | "|list|"  | A whitelist of allowed filters,   |
    |           |           |           | separated by commas. Wildcards    |
    |           |           |           | supported.                        |
    +-----------+-----------+-----------+-----------------------------------+

..  warning::
    The trigger should only contain alphanumeric characters (in particular,
    do not add any prefix, like "!" to that value).

Example
-------

In this example, we configure the bot to allow only a few string filters
to be used (toupper which turns all letters into uppercase, tolower which
turns them into lowercase letters and rot13 which applies a 13 letters
rotation to text, much like Caesar's cipher).

..  parsed-code:: xml

    <?xml version="1.0"?>
    <configuration
      xmlns="http://localhost/Erebot/"
      version="0.20"
      language="fr-FR"
      timezone="Europe/Paris">

      <modules>
        <!-- Other modules ignored for clarity. -->

        <module name="|project|">
          <param name="whitelist" value="string.toupper,string.tolower,string.rot13" />
        </module>
      </modules>
    </configuration>


..  |list| replace:: string.*,convert.*

.. vim: ts=4 et
