build_isc_dhcp
==============

This is the module that will enable the ability to extract and build ISC DHCP server configurations from the database. It will output the configuration text that would normally be located in something like /etc/dhcpd.conf or similar.

Install
-------


  * If you have not already, run the following command `echo '/opt/ona' > /etc/onabase`.  This assumes you installed ONA into /opt/ona 
  * Ensure you have the following prerequisites installed:
    * An ISC DHCP server. It is not required to be on the same host as the ONA system.
    * `sendEmail` for notification messages. [Download here](http://caspian.dotconf.net/menu/Software/SendEmail/) or use the package from your distribution.
    * A functioning dcm.pl install on your DHCP server.
  * Download the archive and place it in your $ONABASE/www/local/plugins directory, the directory must be named `build_isc_dhcp`
  * Make the plugin directory owned by your webserver user I.E.: `chown -R www-data /opt/ona/www/local/plugins/build_isc_dhcp`
  * From within the GUI, click _Plugins->Manage Plugins_ while logged in as an admin user
  * Click the install icon for the plugin which should be listed by the plugin name 
  * Follow any instructions it prompts you with.
  * Install the $ONABASE/www/local/plugins/build_isc_dhcp/build_dhcpd script on your DHCP server. It is suggested to place it in /opt/ona/bin
  * Copy the variables at the top of the build_dhcpd script and add them to `/opt/ona/etc/build_dhcpd.conf` making adjustments as needed.
  * If you wish you can just modify the variables at the top of the build_dhcpd script to suit your environment instead of making the .conf file above.

Usage
-----
First off, you must have at least one subnet defined in the database as well as a host definition for the server you will be running the DHCP server on.  This host definition should have the same name and IP address as what your server is actually configured to use.  

The host within ONA should be defined as a DHCP server for whatever subnets you expect it to be responsible for.  You must also have a default gateway defined for the subnet and any DHCP pools that may exist.  The install process above should have also created a system configuration variable called "build_dhcp_type" with a value of "isc".

You should now see the configuration being built real time in the web interface each time you select the server host and view its DHCP server display page.

This now also exposes the [dcm.pl](https://github.com/opennetadmin/dcm) module called _build_dhcpd_.  It is used by the build_dhcpd script to extract the configuration.  It is also used by the web interface to generate configuration data.

There are a few configuration options in the build script that should be examined.  Edit the variables at the top of the file `/opt/ona/bin/build_dhcpd`
or better yet, add the following to `/opt/ona/etc/build_dhcpd.conf` with adjusted options as needed:
    # this will default to placing data files in /opt/ona/etc/dhcpd, you can update the following for your system as needed
    # for things like chroot jails etc
    ONA_PATH="${ONABASE}/etc/dhcpd"
    
    # Get the local hosts FQDN.  It will be an assumption!! that it is the same as the hostname in ONA
    # Also, the use of hostname -f can vary from system type to system type.  be aware!
    SRV_FQDN=`hostname -f`
    
    # Path to the dcm.pl command.  Also include any options that might be needed
    DCM_PATH="${ONABASE}/bin/dcm.pl"
    
    # For now a path is required to a default header.
    # this will have things like the authoritative statement,ddns-update-style, and other required options
    # this header should contain things that are static and rarely change
    # it can also contain localized configuration not maintained by ONA
    # This value must not be blank
    HEADER_PATH="${ONA_PATH}/dhcpd.conf.ona.header"
    
    # Remove the temporary configuration files that are older than $DAYSOLD.  This uses the find
    # command to remove anything older than this amount of days.  These are configs that had an
    # error for some reason.
    DAYSOLD=30
    
    # The path to the system init script that is responsible for restarting the dhcpd service
    # also include the restart option to the init script that is appropriate for your system
    SYSTEMINIT="/etc/init.d/dhcpd restart"
    
    # The systems DHCPD binary file.  Enter full path if needed
    DHCPDBIN=dhcpd
    
    # Email settings for config_archive to send status information to (diffs etc)
    # Comment out the MAIL_TO line to disable sending of error related email notifications.
    MAIL_SERVER=mail.example.com               # name or IP of the mail server to use
    MAIL_FROM=ona-build_dhcpd@$SRV_FQDN        # email address to use in the from field
    MAIL_TO=someone@example.com                # email address(es) to send our notifications to
    
Most DHCPD servers default to using `/etc/dhcpd.conf` or similar as their config.  You should make this a symbolic link to `/opt/ona/etc/dhcpd/dhcpd.conf.ona`.  This build script will automatically add an include statement that points to $ONABASE/etc/dhcpd/dhcpd.conf.ona.header at the top of the dhcpd.conf.ona config file built from the database.  This header file is required and must contain directives like "authoritative", dns-update-style, or logging statements etc as these are out of scope for the ONA system to manage at this point. Here is an example header file that you can use:

    # BEGIN ONA HEADER FILE #
    # This file is required for DHCP to work when managed by ONA.  It handles various
    # rarely changing values that the ONA system does not currently manage.  It also
    # provides a way to add nonstandard or complex configuration that ONA can not generate.
    
    ddns-updates off;
    ddns-update-style none;
    
    # Point this to where your system defaults its dhcpd.leases file.  Or you can
    # specify your own location
    #lease-file-name "/var/lib/dhcp/db/dhcpd.leases";
    
    default-lease-time 604800;
    max-lease-time 604800;
    
    # log to a specific log file defined in syslog for local7, check your syslog.conf 
    # file to find where the local7 facility will log to.  Change the facility name as needed.
    #log-facility local7;
    
    # Make this server authoritative for all subnets.  Will send DHCPNACK for unknown devices/leases
    authoritative;
    
    ####### Start PXE configuration #############
    # If you use PXE, this provides a standardized response just for PXE clients only
    # It is a nice clean way to implement PXE for the entire server.  You will want to 
    # ensure that the filename declaration below is correct.
    option space PXE;
    option PXE.magic                code 208 = string;
    
    class "PXE" {
            match if substring(option vendor-class-identifier, 0, 9) = "PXEClient";
            log (info, concat("######### PXEClient Match  ", binary-to-ascii (16, 8, ":", hardware), " ###########"));
            option vendor-class-identifier "PXEClient";
    
            site-option-space "PXE";
            option PXE.magic f1:00:74:7e;
    
            if exists dhcp-parameter-request-list {
                    append dhcp-parameter-request-list 1,3,6,12,15,17,66,208;
            }
    
            # If the server running DHCPD is not your tftp boot server change this option as needed.
            # next-server some.server.example.com;
    
            # Point this to your PXE boot file
            filename "/pxes/pxelinux.0";
    }
    ####### End PXE configuration #############
    
    ###### Add local config here for options unsupported by ONA ##########
    # Be aware that global DHCP options managed by ONA will be defined after this file
    # This means you will be unable to use them in any definitions created here.
    
    use-host-decl-names on;
    
    # END ONA HEADER FILE #
    
Now that it is installed you should be able to execute `/opt/ona/bin/build_dhcpd` as root.  This will build a configuration file from the data in ONA, test its syntax, and place it into the file `/opt/ona/etc/dhcpd.conf.ona`.  Since the config file first includes the header file, when the test is ran it will process what is in the dhcpd.conf.ona.header file, then process what was built from the database.  If it is successful it will restart the dhcp daemon using the init program defined in the "SYSTEMINIT" config variable.


Once you have a successful rebuild of your configuration, you can then put the  /opt/ona/bin/build_dhcpd build script into a cron that runs at whatever interval you see as appropriate for your environment.  I would suggest at least 2 times a day all the way down to once every 15 minutes.  Remember, you can always run it on demand if needed.  You will need to run it as root since it needs to restart the DHCP daemon.

Many modern linux systems use the /etc/cron.d method.  You can put ONA related cron jobs into this directory.  As an example you can create a file called /etc/cron.d/ona with the following content:
    # Please store only OpenNetAdmin related cron entries here.
    PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin/:/opt/ona/bin
    
    # Rebuild DHCPD configuration file and restart daemon every hour
    0 * * * * root /opt/ona/bin/build_dhcpd > /dev/null 2>&1

IPv6
----
Initial IPv6 support is started but much more work to come.  Wanted to mention some IPv6 related items here.

  * v6 support is still only partially implmented
  * If you are running newer versions of ONA that support v6 then you will want to ensure that you don't define your ipv6 subnets on DHCP servers that service ipv4 addresses.  You will currently get a configuration that has mixed v4/v6 configuration which will not work properly. If you mix your subnets on a single server within ONA you will likely break your current system.  The latest version of this plugin (1.2) should help mitigate that issue, but just don't associate both types of subnets within ONA.
  * At the moment an understanding of how fixed-address (aka MAC address) will work with IPv6 as it relates to the host-identifier will need to be worked through and understood for the proper method.  Here is a reference of the issue which does not seem to be fully resolved as of March 2012: https://lists.isc.org/pipermail/dhcp-users/2009-March/008678.html 
