Custom-From README file
=======================

Overview
--------

This plugin adds a blue button to the compose screen, next to the identities
selection dropdown. By clicking it, a textbox will replace the dropdown,
allowing you to enter whatever you want as sender value (it must be a valid
"From:" header field value, though).

When replying to an e-mail sent to you through an address not in your
identities list, plugin will automatically fire and set "From:" header to the
address the original e-mail was sent to.

Install (with Composer):
------------------------

Execute `composer require roundcube/custom-from` from your RoundCube install
folder.

Install (manually):
-------------------

Clone repository content to a `custom_from` folder inside your RoundCube
`plugins` directory, so that file `custom_from.php` file can be found at
`<RoundCube install folder>/plugins/custom_from/custom_from.php`.

    cd <RoundCube install folder>
    git clone https://github.com/r3c/custom_from.git

Then add a reference to this plugin in RoundCube plugins list located in
`<RoundCube install folder>/config/main.inc.php` configuration file (update the
`$config['plugins']` variable). Ensure your web user has read access to the
plugin directory and all files in it.

Usage
-----

Once plugin is installed, custom sender button will appear at the right
hand side of the identity selection list.

If you want to disable the "automatic replacement on reply" feature, rename
`config.inc.php.dist` file into `config.inc.php`, uncomment the line with a
parameter named `custom_from_compose_auto` and set this value to `false`.

Thanks
------

- dwurf (https://github.com/dwurf) for the globals $IMAP and $USER fix
- Peter Dey (https://github.com/peterdey) for the custom header feature
- kermit-the-frog (https://github.com/kermit-the-frog) for various bugfixes
