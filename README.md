# Custom-From README file

## Overview

This plugin adds a blue button to the compose screen, next to the identities
selection dropdown. By clicking it, a textbox will replace the dropdown,
allowing you to enter whatever you want as sender value (it must be a valid
"From:" header field value, though).

When replying to an e-mail sent to you through an address not in your
identities list, plugin will automatically fire and set "From:" header to the
address the original e-mail was sent to.

## Install

### Option 1: install with Composer

Execute `composer require r3c/custom-from` from your RoundCube install
directory and run the install command. See instructions from RoundCube website
for details: https://plugins.roundcube.net/.

### Option 2: install manually

Clone repository content to a `custom_from` directory inside your RoundCube
`plugins` directory, so that file `custom_from.php` file can be found at
`$ROUNDCUBE_INSTALL_DIRECTORY/plugins/custom_from/custom_from.php`.

    cd $ROUNDCUBE_INSTALL_DIRECTORY
    git clone https://github.com/r3c/custom_from.git

Then reference plugin by adding an item "custom_from" to RoundCube plugins list
in configuration (variable `$config['plugins']` variable in file
`$ROUNDCUBE_INSTALL_DIRECTORY/config/main.inc.php`). Ensure your web user has
read access to the plugin directory and all files in it.

## Usage

Once plugin is installed, custom sender button will appear at the right
hand side of the identity selection list.

Open "Reply address (Custom From)" in user preferences to configure how plugin
should behave when replying to an e-mail.

## Thanks

- dwurf (https://github.com/dwurf) for the globals $IMAP and $USER fix
- Peter Dey (https://github.com/peterdey) for the custom header feature
- kermit-the-frog (https://github.com/kermit-the-frog) for various bugfixes
